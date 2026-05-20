<?php
declare(strict_types=1);

use RareFolio\Api\Response;
use RareFolio\Api\Validator;
use RareFolio\Blockfrost\Client as BlockfrostClient;
use RareFolio\Config;
use RareFolio\Db;

/**
 * GET /api/v1/tokens/{id}
 *
 * Lookup a single CNFT by its rarefolio_token_id (e.g. "qd-silver-0000001").
 * Returns enough information to power verify.html / nft.html on the public site
 * without exposing internal-only columns.
 *
 * @var array{id:string} $params supplied by the router
 */

try {
    $cnftId = Validator::cnftId((string) ($params['id'] ?? ''));
} catch (InvalidArgumentException $e) {
    Response::badRequest($e->getMessage());
    exit;
}

/**
 * @param array<string,mixed> $decoded
 * @return array<string,mixed>
 */
function resolveCip25TokenMetadata(
    array $decoded,
    string $policyId,
    string $assetNameUtf8,
    string $assetNameHex,
    string $tokenId
): array {
    if (!isset($decoded['721']) || !is_array($decoded['721'])) {
        return $decoded;
    }

    $v721 = $decoded['721'];
    $policyCandidates = [];
    foreach ([$policyId, strtolower($policyId), strtoupper($policyId)] as $candidate) {
        if (is_string($candidate) && $candidate !== '' && !in_array($candidate, $policyCandidates, true)) {
            $policyCandidates[] = $candidate;
        }
    }
    foreach ($v721 as $policyKey => $policyBlock) {
        if ($policyKey === 'version' || !is_array($policyBlock)) {
            continue;
        }
        $pk = (string) $policyKey;
        if (!in_array($pk, $policyCandidates, true)) {
            $policyCandidates[] = $pk;
        }
    }

    $assetCandidates = [];
    foreach ([$assetNameUtf8, $tokenId, $assetNameHex, strtolower($assetNameHex), strtoupper($assetNameHex)] as $candidate) {
        if (is_string($candidate) && $candidate !== '' && !in_array($candidate, $assetCandidates, true)) {
            $assetCandidates[] = $candidate;
        }
    }

    foreach ($policyCandidates as $policyKey) {
        $policyBlock = $v721[$policyKey] ?? null;
        if (!is_array($policyBlock)) {
            continue;
        }
        foreach ($assetCandidates as $assetKey) {
            $assetMeta = $policyBlock[$assetKey] ?? null;
            if (is_array($assetMeta)) {
                return $assetMeta;
            }
        }
        foreach ($policyBlock as $assetMeta) {
            if (is_array($assetMeta)) {
                return $assetMeta;
            }
        }
    }

    return $decoded;
}

/**
 * @param array<string,mixed> $meta
 */
function cip25AttributeValue(array $meta, string $key): mixed
{
    $attrs = $meta['attributes'] ?? null;
    if (!is_array($attrs)) {
        return null;
    }

    if (array_keys($attrs) !== range(0, count($attrs) - 1)) {
        return $attrs[$key] ?? null;
    }

    $target = strtolower(trim($key));
    foreach ($attrs as $item) {
        if (!is_array($item)) {
            continue;
        }
        $trait = firstStringValue([
            $item['trait_type'] ?? null,
            $item['trait'] ?? null,
            $item['name'] ?? null,
            $item['key'] ?? null,
        ]);
        if ($trait === null || strtolower($trait) !== $target) {
            continue;
        }
        return $item['value'] ?? $item['val'] ?? $item['data'] ?? null;
    }

    return null;
}

if (!Config::get('DB_NAME') || !Config::get('DB_USER')) {
    Response::error(503, 'database not configured');
    exit;
}

try {
    $pdo = Db::pdo();
    $stmt = $pdo->prepare('
        SELECT
            t.rarefolio_token_id,
            t.policy_id,
            t.asset_name_hex,
            t.asset_name_utf8,
            t.asset_fingerprint,
            t.collection_slug,
            c.network AS collection_network,
            c.primary_sale_price_lovelace AS collection_price,
            t.title,
            t.character_name,
            t.edition,
            t.artist,
            t.mint_tx_hash,
            t.minted_at,
            t.current_owner_wallet,
            t.custody_status,
            t.listing_status,
            t.primary_sale_status,
            t.secondary_eligible,
            t.cip25_json,
            t.updated_at
        FROM qd_tokens t
        LEFT JOIN qd_collections c
            ON c.slug = t.collection_slug
        WHERE t.rarefolio_token_id = :id
        LIMIT 1
    ');
    $stmt->execute([':id' => $cnftId]);
    $row = $stmt->fetch();
} catch (Throwable $e) {
    error_log('[api v1 tokens_show] ' . $e->getMessage());
    Response::error(500, 'database error');
    exit;
}

if (!$row) {
    Response::notFound('token not found: ' . $cnftId);
    exit;
}
hydrateChainFieldsIfMissing($pdo, $row);

// Try to pull bar_serial from CIP-25 attributes; fall back to null if unknown.
$barSerial = null;
$cip25Resolved = null;
$cip25Root = null;
if (!empty($row['cip25_json'])) {
    $decoded = json_decode((string) $row['cip25_json'], true) ?: null;
    $cip25Root = is_array($decoded) ? $decoded : null;
    $cip25Resolved = is_array($decoded)
        ? resolveCip25TokenMetadata(
            $decoded,
            (string) ($row['policy_id'] ?? ''),
            (string) ($row['asset_name_utf8'] ?? ''),
            (string) ($row['asset_name_hex'] ?? ''),
            (string) ($row['rarefolio_token_id'] ?? '')
        )
        : null;
    if (is_array($cip25Resolved)) {
        $candidates = [
            $cip25Resolved['bar_serial']               ?? null,
            $cip25Resolved['attributes']['bar_serial'] ?? null,
            $cip25Resolved['properties']['bar_serial'] ?? null,
            cip25AttributeValue($cip25Resolved, 'bar_serial'),
        ];
        foreach ($candidates as $c) {
            if (is_string($c) && $c !== '') { $barSerial = $c; break; }
        }
    }
    if ($barSerial === null && is_array($cip25Root)) {
        $rootCandidates = [
            $cip25Root['bar_serial']               ?? null,
            $cip25Root['attributes']['bar_serial'] ?? null,
            $cip25Root['properties']['bar_serial'] ?? null,
            cip25AttributeValue($cip25Root, 'bar_serial'),
        ];
        foreach ($rootCandidates as $c) {
            if (is_string($c) && $c !== '') { $barSerial = $c; break; }
        }
    }
}

$proofManifestUri = null;
$proofEvidenceUrl = null;
$companionAssetName = null;
$companionTxHash = null;
$companionStatus = null;
$companionEnabledFlag = null;
if (is_array($cip25Resolved)) {
    $proofManifestUri = firstStringValue([
        $cip25Resolved['proof_manifest_uri'] ?? null,
        $cip25Resolved['proof_manifest'] ?? null,
        $cip25Resolved['manifest_uri'] ?? null,
        $cip25Resolved['proof']['manifest_uri'] ?? null,
        $cip25Resolved['attributes']['proof_manifest_uri'] ?? null,
        cip25AttributeValue($cip25Resolved, 'proof_manifest_uri'),
        cip25AttributeValue($cip25Resolved, 'manifest_uri'),
    ]);
    $proofEvidenceUrl = firstStringValue([
        $cip25Resolved['evidence_public_url'] ?? null,
        $cip25Resolved['proof_evidence_url'] ?? null,
        $cip25Resolved['proof']['evidence_public_url'] ?? null,
        $cip25Resolved['evidence']['public_url'] ?? null,
        $cip25Resolved['attributes']['evidence_public_url'] ?? null,
        cip25AttributeValue($cip25Resolved, 'evidence_public_url'),
        cip25AttributeValue($cip25Resolved, 'proof_evidence_url'),
    ]);
    $companionAssetName = firstStringValue([
        $cip25Resolved['companion_asset_name'] ?? null,
        $cip25Resolved['companion']['asset_name'] ?? null,
        $cip25Resolved['attributes']['companion_asset_name'] ?? null,
        $cip25Resolved['silver_shard_name'] ?? null,
        $cip25Resolved['silver_shard_asset_name_utf8'] ?? null,
        $cip25Resolved['attributes']['silver_shard_name'] ?? null,
        cip25AttributeValue($cip25Resolved, 'companion_asset_name'),
        cip25AttributeValue($cip25Resolved, 'silver_shard_name'),
        cip25AttributeValue($cip25Resolved, 'silver_shard_asset_name_utf8'),
    ]);
    $companionTxHash = firstStringValue([
        $cip25Resolved['companion_tx_hash'] ?? null,
        $cip25Resolved['companion']['tx_hash'] ?? null,
        $cip25Resolved['attributes']['companion_tx_hash'] ?? null,
        $cip25Resolved['silver_shard_mint_tx_hash'] ?? null,
        $cip25Resolved['attributes']['silver_shard_mint_tx_hash'] ?? null,
        cip25AttributeValue($cip25Resolved, 'companion_tx_hash'),
        cip25AttributeValue($cip25Resolved, 'silver_shard_mint_tx_hash'),
    ]);
    $companionStatus = firstStringValue([
        $cip25Resolved['companion_status'] ?? null,
        $cip25Resolved['companion']['status'] ?? null,
        $cip25Resolved['companion']['delivery']['status'] ?? null,
        $cip25Resolved['attributes']['companion_status'] ?? null,
        cip25AttributeValue($cip25Resolved, 'companion_status'),
    ]);
    $companionEnabledFlag = firstBoolValue([
        $cip25Resolved['companion_enabled'] ?? null,
        $cip25Resolved['companion']['enabled'] ?? null,
        $cip25Resolved['attributes']['companion_enabled'] ?? null,
        $cip25Resolved['silver_shard_enabled'] ?? null,
        $cip25Resolved['attributes']['silver_shard_enabled'] ?? null,
        cip25AttributeValue($cip25Resolved, 'companion_enabled'),
        cip25AttributeValue($cip25Resolved, 'silver_shard_enabled'),
    ]);
}
if (is_array($cip25Root)) {
    if ($proofManifestUri === null) {
        $proofManifestUri = firstStringValue([
            $cip25Root['proof_manifest_uri'] ?? null,
            $cip25Root['proof_manifest'] ?? null,
            $cip25Root['manifest_uri'] ?? null,
            $cip25Root['proof']['manifest_uri'] ?? null,
            $cip25Root['attributes']['proof_manifest_uri'] ?? null,
            cip25AttributeValue($cip25Root, 'proof_manifest_uri'),
            cip25AttributeValue($cip25Root, 'manifest_uri'),
        ]);
    }
    if ($proofEvidenceUrl === null) {
        $proofEvidenceUrl = firstStringValue([
            $cip25Root['evidence_public_url'] ?? null,
            $cip25Root['proof_evidence_url'] ?? null,
            $cip25Root['proof']['evidence_public_url'] ?? null,
            $cip25Root['evidence']['public_url'] ?? null,
            $cip25Root['attributes']['evidence_public_url'] ?? null,
            cip25AttributeValue($cip25Root, 'evidence_public_url'),
            cip25AttributeValue($cip25Root, 'proof_evidence_url'),
        ]);
    }
    if ($companionAssetName === null) {
        $companionAssetName = firstStringValue([
            $cip25Root['companion_asset_name'] ?? null,
            $cip25Root['companion']['asset_name'] ?? null,
            $cip25Root['attributes']['companion_asset_name'] ?? null,
            $cip25Root['silver_shard_name'] ?? null,
            $cip25Root['silver_shard_asset_name_utf8'] ?? null,
            $cip25Root['attributes']['silver_shard_name'] ?? null,
            cip25AttributeValue($cip25Root, 'companion_asset_name'),
            cip25AttributeValue($cip25Root, 'silver_shard_name'),
            cip25AttributeValue($cip25Root, 'silver_shard_asset_name_utf8'),
        ]);
    }
    if ($companionTxHash === null) {
        $companionTxHash = firstStringValue([
            $cip25Root['companion_tx_hash'] ?? null,
            $cip25Root['companion']['tx_hash'] ?? null,
            $cip25Root['attributes']['companion_tx_hash'] ?? null,
            $cip25Root['silver_shard_mint_tx_hash'] ?? null,
            $cip25Root['attributes']['silver_shard_mint_tx_hash'] ?? null,
            cip25AttributeValue($cip25Root, 'companion_tx_hash'),
            cip25AttributeValue($cip25Root, 'silver_shard_mint_tx_hash'),
        ]);
    }
    if ($companionStatus === null) {
        $companionStatus = firstStringValue([
            $cip25Root['companion_status'] ?? null,
            $cip25Root['companion']['status'] ?? null,
            $cip25Root['companion']['delivery']['status'] ?? null,
            $cip25Root['attributes']['companion_status'] ?? null,
            cip25AttributeValue($cip25Root, 'companion_status'),
        ]);
    }
    if ($companionEnabledFlag === null) {
        $companionEnabledFlag = firstBoolValue([
            $cip25Root['companion_enabled'] ?? null,
            $cip25Root['companion']['enabled'] ?? null,
            $cip25Root['attributes']['companion_enabled'] ?? null,
            $cip25Root['silver_shard_enabled'] ?? null,
            $cip25Root['attributes']['silver_shard_enabled'] ?? null,
            cip25AttributeValue($cip25Root, 'companion_enabled'),
            cip25AttributeValue($cip25Root, 'silver_shard_enabled'),
        ]);
    }
}
$isFoundersCollection = in_array((string) $row['collection_slug'], ['silverbar-01-founders-v2', 'silverbar-01-founders'], true);
if ($isFoundersCollection) {
    if ($proofManifestUri === null) {
        $proofManifestUri = 'https://rarefolio.io/assets/img/collection/scnft_founders/manifest.json';
    }
    if ($proofEvidenceUrl === null) {
        $proofEvidenceUrl = 'https://rarefolio.io/assets/img/collection/scnft_founders/master_sha256_hash_ipfs.md';
    }
    if ($companionAssetName === null) {
        $companionAssetName = 'Actual Silver Shard';
    }
    if ($companionEnabledFlag === null) {
        $companionEnabledFlag = true;
    }
}
if ($companionTxHash !== null && !preg_match('/^[0-9a-f]{64}$/i', $companionTxHash)) {
    $companionTxHash = null;
}
$companionEnabled = ($companionEnabledFlag === true) || $companionAssetName !== null;
if ($companionStatus === null) {
    $companionStatus = $companionEnabled
        ? ($companionTxHash !== null ? 'confirmed' : 'not_queued')
        : 'not_enabled';
}

// Runtime env is the source of truth for active network.
// Collection-declared network is retained for diagnostics/admin drift checks.
$runtimeNetwork = strtolower((string) Config::get('BLOCKFROST_NETWORK', 'preprod'));
if (in_array($runtimeNetwork, ['mainnet', 'preprod', 'preview'], true)) {
    $network = $runtimeNetwork;
} else {
    $network = (string) ($row['collection_network'] ?? 'preprod');
}

// Redact wallet to first/last 6 chars — full ownership is not a public field.
$ownerDisplay = null;
$w = $row['current_owner_wallet'];
if (is_string($w) && strlen($w) > 14) {
    $ownerDisplay = substr($w, 0, 8) . '…' . substr($w, -6);
} elseif (is_string($w) && $w !== '') {
    $ownerDisplay = $w;
}

$collectionPrice = (int) ($row['collection_price'] ?? 0);
$isOnDemand = $row['primary_sale_status'] === 'unminted'
    && in_array((string) $row['listing_status'], ['listed_fixed', 'listed_auction', 'offer_only'], true)
    && $collectionPrice > 0;
$mintMode = $isOnDemand ? 'on_demand' : 'pre_minted';

Response::ok([
    'cnft_id'          => $row['rarefolio_token_id'],
    'title'            => $row['title'],
    'character_name'   => $row['character_name'],
    'edition'          => $row['edition'],
    'artist'           => $row['artist'],
    'collection'       => $row['collection_slug'],
    'bar_serial'       => $barSerial,
    'chain'            => [
        'network'            => $network,
        'policy_id'          => $row['policy_id'],
        'asset_name_hex'     => $row['asset_name_hex'],
        'asset_name_utf8'    => $row['asset_name_utf8'],
        'asset_fingerprint'  => $row['asset_fingerprint'],
        'mint_tx_hash'       => $row['mint_tx_hash'],
        'minted_at'          => $row['minted_at'],
    ],
    'status'           => [
        'primary_sale'      => $row['primary_sale_status'],
        'listing'           => $row['listing_status'],
        'mint_mode'         => $mintMode,
        'custody'           => $row['custody_status'],
        'secondary_eligible'=> (bool) ((int) $row['secondary_eligible']),
    ],
    'owner_display'    => $ownerDisplay,
    'companion'        => [
        'enabled'    => $companionEnabled,
        'asset_name' => $companionAssetName,
        'delivery'   => [
            'status'  => $companionStatus,
            'tx_hash' => $companionTxHash,
        ],
    ],
    'proof'            => [
        'manifest_uri'        => $proofManifestUri,
        'evidence_public_url' => $proofEvidenceUrl,
    ],
    'updated_at'       => $row['updated_at'],
]);

/**
 * @param array<string,mixed> $row
 */
function hydrateChainFieldsIfMissing(PDO $pdo, array &$row): void
{
    $primary = (string) ($row['primary_sale_status'] ?? '');
    if (!in_array($primary, ['minted', 'sold', 'sold_pre_marketplace'], true)) {
        return;
    }

    $policyId = strtolower((string) ($row['policy_id'] ?? ''));
    $assetHex = strtolower((string) ($row['asset_name_hex'] ?? ''));
    if (!preg_match('/^[0-9a-f]{56}$/', $policyId) || !preg_match('/^[0-9a-f]+$/', $assetHex)) {
        return;
    }

    $needsFingerprint = !is_string($row['asset_fingerprint']) || trim((string) $row['asset_fingerprint']) === '';
    $needsOwner = !is_string($row['current_owner_wallet']) || trim((string) $row['current_owner_wallet']) === '';
    if (!$needsFingerprint && !$needsOwner) {
        return;
    }

    try {
        $bf = new BlockfrostClient();
        $unit = $policyId . $assetHex;
        $asset = $bf->asset($unit);
        $fingerprint = is_array($asset) && !empty($asset['fingerprint']) ? (string) $asset['fingerprint'] : null;
        $owner = $bf->currentOwner($unit);

        $setParts = [];
        $binds = [':token_id' => (string) $row['rarefolio_token_id']];

        if ($needsFingerprint && $fingerprint !== null && $fingerprint !== '') {
            $row['asset_fingerprint'] = $fingerprint;
            $setParts[] = 'asset_fingerprint = :fingerprint';
            $binds[':fingerprint'] = $fingerprint;
        }

        if ($owner !== null && $owner !== '' && ((string) ($row['current_owner_wallet'] ?? '') !== $owner)) {
            $row['current_owner_wallet'] = $owner;
            $setParts[] = 'current_owner_wallet = :owner_wallet';
            $binds[':owner_wallet'] = $owner;
        }

        if ($setParts !== []) {
            $setParts[] = 'updated_at = NOW()';
            $sql = 'UPDATE qd_tokens SET ' . implode(', ', $setParts) . ' WHERE rarefolio_token_id = :token_id LIMIT 1';
            $pdo->prepare($sql)->execute($binds);
        }
    } catch (Throwable $e) {
        error_log('[api v1 tokens_show hydrate] ' . $e->getMessage());
    }
}

/**
 * @param array<int,mixed> $candidates
 */
function firstStringValue(array $candidates): ?string
{
    foreach ($candidates as $value) {
        if (is_string($value)) {
            $trimmed = trim($value);
            if ($trimmed !== '') {
                return $trimmed;
            }
        }
    }
    return null;
}

/**
 * @param array<int,mixed> $candidates
 */
function firstBoolValue(array $candidates): ?bool
{
    foreach ($candidates as $value) {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value)) {
            if ($value === 1) return true;
            if ($value === 0) return false;
        }
        if (is_string($value)) {
            $v = strtolower(trim($value));
            if (in_array($v, ['1', 'true', 'yes', 'on'], true)) return true;
            if (in_array($v, ['0', 'false', 'no', 'off'], true)) return false;
        }
    }
    return null;
}
