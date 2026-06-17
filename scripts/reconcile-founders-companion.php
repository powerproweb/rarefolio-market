<?php
/**
 * DRAFT — reconcile already-completed Founders companion sends into the DB.
 * Status: PROPOSAL. Reviewed by Juan José/Warp before running. Nothing runs on import.
 *
 * WHY: docs/FOUNDERS_COMPANION_TX_LEDGER_2026-05-19.md shows companions for
 * qd-silver-0000705..0000712 were already delivered on-chain (qty 1 each). The site
 * still shows "not queued" only because qd_tokens.cip25_json was never updated with
 * companion_status/tx_hash. This script writes that metadata so the public pages
 * reflect reality AND so companion-dispatch.php's idempotency guard becomes correct
 * (preventing a future accidental DOUBLE-SEND). It does NOT move any tokens.
 *
 * PLACE AT:  01a_rarefolio_market/scripts/reconcile-founders-companion.php
 * DRY RUN:   php scripts/reconcile-founders-companion.php          (default; writes nothing)
 * APPLY:     php scripts/reconcile-founders-companion.php --apply
 *
 * BEFORE --apply:
 *   1) Verify on-chain that each of 705..712 currently holds exactly 1 of the real
 *      companion unit (ARGENTUM_PRIME_Bar01). The ledger had mistaken sends + reclaim +
 *      burn, so confirm FINAL state per token (sidecar /companion/treasury/.../unit/...
 *      or a Cardano explorer). If any token does NOT hold it, remove it from $LEDGER.
 *   2) Back up qd_tokens (or at least the cip25_json of these 8 rows). Dry-run prints
 *      the current JSON so you have a copy.
 *
 * NOTE: the helper functions below are copied verbatim from
 * api/private/companion-dispatch.php so the written shape is identical. Recommended
 * follow-up: refactor those into src/Companion/Metadata.php shared by both files to
 * avoid copy-drift.
 */
declare(strict_types=1);

require_once __DIR__ . '/../src/Config.php';
require_once __DIR__ . '/../src/Db.php';

use RareFolio\Config;
use RareFolio\Db;

Config::load(__DIR__ . '/../.env');

/** Real companion unit (ARGENTUM_PRIME_Bar01) per the ledger. */
const COMPANION_UNIT = '46cd5216baf9e1e81771731570e408fb4c392cc38db59f55ee8599a1415247454e54554d5f5052494d455f4261723031';

/** token_id => correct-send tx hash (ledger section 3, "Correct real companion sends"). */
$LEDGER = [
    'qd-silver-0000705' => 'b83dda76227aa6b40af72ac998ef02cedf4a654337e533d0f11c607a33380851',
    'qd-silver-0000706' => '25942e9bce762c6024f0af76eb131e99ec2ba59e350dfeec7cc85c18e4834862',
    'qd-silver-0000707' => '9b800c81aef49f7558d8e5ab1a5866086257fa36016bc7cce0b10a674f4169b9',
    'qd-silver-0000708' => '6499381547c1117b93f54c7c1e6c3871cf83f0922f61616316a778567fc98cb0',
    'qd-silver-0000709' => 'f870e90f0092f7065a5ecb6c90c2844d28129b651f969302adee22296764ff64',
    'qd-silver-0000710' => 'c55343c07e0e4f77bb048cfdf059499955ad59b3b55b66001fddd85ad69ee7f8',
    'qd-silver-0000711' => '96d493411c8a13e6625c3bfcc9d089691b8d3377918fe27b9e68aba0e8835bb9',
    'qd-silver-0000712' => 'f61376d2e014fc5f9215fdfa182e1a1fd90b7150f88f94021e5e214939a15da1',
];

$APPLY = in_array('--apply', $argv, true);

// --------------------------------------------------------------------------
// Helpers copied verbatim from api/private/companion-dispatch.php
// --------------------------------------------------------------------------

function decode_cip25_json(mixed $raw): array
{
    if (!is_string($raw) || trim($raw) === '') return [];
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function companion_already_submitted(array $cip25): bool
{
    $statusCandidates = [
        $cip25['companion_status'] ?? null,
        $cip25['companion']['status'] ?? null,
        $cip25['companion']['delivery']['status'] ?? null,
    ];
    foreach ($statusCandidates as $status) {
        if (is_string($status) && strtolower(trim($status)) === 'submitted') return true;
    }
    $txCandidates = [
        $cip25['companion_tx_hash'] ?? null,
        $cip25['companion']['tx_hash'] ?? null,
        $cip25['companion']['delivery']['tx_hash'] ?? null,
    ];
    foreach ($txCandidates as $txHash) {
        if (is_string($txHash) && preg_match('/^[0-9a-f]{64}$/i', trim($txHash)) === 1) return true;
    }
    return false;
}

function resolve_v721_path_keys(array $cip25, string $policyId, string $assetNameUtf8, string $assetNameHex, string $tokenId): array
{
    if (!isset($cip25['721']) || !is_array($cip25['721'])) return [null, null];
    $v721 = $cip25['721'];
    $policyCandidates = [];
    foreach ([$policyId, strtolower($policyId), strtoupper($policyId)] as $candidate) {
        if (is_string($candidate) && $candidate !== '' && !in_array($candidate, $policyCandidates, true)) $policyCandidates[] = $candidate;
    }
    foreach ($v721 as $policyKey => $policyBlock) {
        if ($policyKey === 'version' || !is_array($policyBlock)) continue;
        $pk = (string) $policyKey;
        if (!in_array($pk, $policyCandidates, true)) $policyCandidates[] = $pk;
    }
    $selectedPolicy = null;
    foreach ($policyCandidates as $policyCandidate) {
        if (is_array($v721[$policyCandidate] ?? null)) { $selectedPolicy = $policyCandidate; break; }
    }
    if ($selectedPolicy === null) return [null, null];
    $policyBlock = $v721[$selectedPolicy];
    $assetCandidates = [];
    foreach ([$assetNameUtf8, $tokenId, $assetNameHex, strtolower($assetNameHex), strtoupper($assetNameHex)] as $candidate) {
        if (is_string($candidate) && $candidate !== '' && !in_array($candidate, $assetCandidates, true)) $assetCandidates[] = $candidate;
    }
    foreach ($assetCandidates as $assetCandidate) {
        if (isset($policyBlock[$assetCandidate]) && is_array($policyBlock[$assetCandidate])) return [$selectedPolicy, $assetCandidate];
    }
    foreach ($policyBlock as $assetKey => $assetMeta) {
        if (is_array($assetMeta)) return [$selectedPolicy, (string) $assetKey];
    }
    if ($tokenId !== '') return [$selectedPolicy, $tokenId];
    return [$selectedPolicy, null];
}

function apply_companion_submission(array $cip25, string $txHash, ?string $unit = null, string $policyId = '', string $assetNameUtf8 = '', string $assetNameHex = '', string $tokenId = ''): array
{
    $txHash = strtolower($txHash);
    $unit = $unit !== null ? strtolower(trim($unit)) : null;
    $cip25['companion_enabled'] = true;
    $cip25['companion_status'] = 'submitted';
    $cip25['companion_tx_hash'] = $txHash;
    if ($unit !== null && $unit !== '') { $cip25['companion_unit'] = $unit; $cip25['silver_shard_unit'] = $unit; }

    $companion = is_array($cip25['companion'] ?? null) ? $cip25['companion'] : [];
    $companion['enabled'] = true;
    $companion['status'] = 'submitted';
    $companion['tx_hash'] = $txHash;
    if ($unit !== null && $unit !== '') $companion['unit'] = $unit;
    $delivery = is_array($companion['delivery'] ?? null) ? $companion['delivery'] : [];
    $delivery['status'] = 'submitted';
    $delivery['tx_hash'] = $txHash;
    $companion['delivery'] = $delivery;
    $cip25['companion'] = $companion;

    [$policyKey, $assetKey] = resolve_v721_path_keys($cip25, $policyId, $assetNameUtf8, $assetNameHex, $tokenId);
    if ($policyKey !== null && $assetKey !== null && is_array($cip25['721'] ?? null)) {
        $v721 = $cip25['721'];
        $policyBlock = is_array($v721[$policyKey] ?? null) ? $v721[$policyKey] : [];
        $assetMeta = is_array($policyBlock[$assetKey] ?? null) ? $policyBlock[$assetKey] : [];
        $assetMeta['companion_enabled'] = true;
        $assetMeta['companion_status'] = 'submitted';
        $assetMeta['companion_tx_hash'] = $txHash;
        if ($unit !== null && $unit !== '') { $assetMeta['companion_unit'] = $unit; $assetMeta['silver_shard_unit'] = $unit; }
        $assetCompanion = is_array($assetMeta['companion'] ?? null) ? $assetMeta['companion'] : [];
        $assetCompanion['enabled'] = true;
        $assetCompanion['status'] = 'submitted';
        $assetCompanion['tx_hash'] = $txHash;
        if ($unit !== null && $unit !== '') $assetCompanion['unit'] = $unit;
        $assetDelivery = is_array($assetCompanion['delivery'] ?? null) ? $assetCompanion['delivery'] : [];
        $assetDelivery['status'] = 'submitted';
        $assetDelivery['tx_hash'] = $txHash;
        $assetCompanion['delivery'] = $assetDelivery;
        $assetMeta['companion'] = $assetCompanion;
        $policyBlock[$assetKey] = $assetMeta;
        $v721[$policyKey] = $policyBlock;
        $cip25['721'] = $v721;
    }
    return $cip25;
}

// --------------------------------------------------------------------------
// Run
// --------------------------------------------------------------------------

fwrite(STDERR, ($APPLY ? "APPLY MODE — will write to qd_tokens\n" : "DRY RUN — no writes (use --apply to write)\n"));

$pdo = Db::pdo();
$ids = array_keys($LEDGER);
$placeholders = implode(',', array_fill(0, count($ids), '?'));
$sel = $pdo->prepare(
    "SELECT id, rarefolio_token_id, policy_id, asset_name_hex, asset_name_utf8, cip25_json
       FROM qd_tokens WHERE rarefolio_token_id IN ($placeholders)"
);
$sel->execute($ids);
$rows = [];
foreach ($sel->fetchAll(PDO::FETCH_ASSOC) as $r) { $rows[(string)$r['rarefolio_token_id']] = $r; }

$upd = $pdo->prepare('UPDATE qd_tokens SET cip25_json = ?, updated_at = NOW() WHERE id = ? LIMIT 1');

$written = 0; $skipped = 0; $missing = 0;
foreach ($LEDGER as $tokenId => $txHash) {
    if (!isset($rows[$tokenId])) {
        $missing++; echo "MISSING  $tokenId — no qd_tokens row\n"; continue;
    }
    $row = $rows[$tokenId];
    $cip25 = decode_cip25_json($row['cip25_json'] ?? null);

    if (companion_already_submitted($cip25)) {
        $skipped++; echo "SKIP     $tokenId — already shows a companion tx (no clobber)\n"; continue;
    }

    $new = apply_companion_submission(
        $cip25, $txHash, COMPANION_UNIT,
        (string)($row['policy_id'] ?? ''), (string)($row['asset_name_utf8'] ?? ''), (string)($row['asset_name_hex'] ?? ''), $tokenId
    );
    $encoded = json_encode($new, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($encoded === false) { echo "ERROR    $tokenId — json_encode failed\n"; continue; }

    echo "RECONCILE $tokenId  tx=$txHash\n";
    echo "  OLD cip25_json: " . (string)($row['cip25_json'] ?? '') . "\n";
    echo "  NEW cip25_json: $encoded\n";

    if ($APPLY) { $upd->execute([$encoded, (int)$row['id']]); $written++; }
}

echo "\nSummary: " . ($APPLY ? "$written written" : "0 written (dry run)") . ", $skipped skipped, $missing missing, of " . count($LEDGER) . " ledger entries.\n";
