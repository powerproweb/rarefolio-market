<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/Config.php';
require_once __DIR__ . '/../src/Db.php';

use RareFolio\Config;
use RareFolio\Db;

/**
 * Copy root-level companion dispatch fields into the resolved CIP-25 v721
 * token metadata node for affected Founders tokens.
 *
 * Usage:
 *   php scripts/backfill-companion-v721-founders.php
 *   php scripts/backfill-companion-v721-founders.php qd-silver-0000705 qd-silver-0000706
 */

/**
 * @param array<string,mixed> $cip25
 * @return array{0:?string,1:?string}
 */
function resolveV721PathKeys(
    array $cip25,
    string $policyId,
    string $assetNameUtf8,
    string $assetNameHex,
    string $tokenId
): array {
    if (!isset($cip25['721']) || !is_array($cip25['721'])) {
        return [null, null];
    }
    $v721 = $cip25['721'];

    $policyCandidates = [];
    foreach ([$policyId, strtolower($policyId), strtoupper($policyId)] as $candidate) {
        if ($candidate !== '' && !in_array($candidate, $policyCandidates, true)) {
            $policyCandidates[] = $candidate;
        }
    }
    foreach ($v721 as $policyKey => $policyBlock) {
        if ($policyKey === 'version' || !is_array($policyBlock)) continue;
        $pk = (string) $policyKey;
        if (!in_array($pk, $policyCandidates, true)) {
            $policyCandidates[] = $pk;
        }
    }

    $selectedPolicy = null;
    foreach ($policyCandidates as $policyCandidate) {
        if (isset($v721[$policyCandidate]) && is_array($v721[$policyCandidate])) {
            $selectedPolicy = $policyCandidate;
            break;
        }
    }
    if ($selectedPolicy === null) return [null, null];

    $policyBlock = $v721[$selectedPolicy];
    $assetCandidates = [];
    foreach ([$assetNameUtf8, $tokenId, $assetNameHex, strtolower($assetNameHex), strtoupper($assetNameHex)] as $candidate) {
        if ($candidate !== '' && !in_array($candidate, $assetCandidates, true)) {
            $assetCandidates[] = $candidate;
        }
    }
    foreach ($assetCandidates as $assetCandidate) {
        if (isset($policyBlock[$assetCandidate]) && is_array($policyBlock[$assetCandidate])) {
            return [$selectedPolicy, $assetCandidate];
        }
    }
    foreach ($policyBlock as $assetKey => $assetMeta) {
        if (is_array($assetMeta)) {
            return [$selectedPolicy, (string) $assetKey];
        }
    }
    if ($tokenId !== '') return [$selectedPolicy, $tokenId];
    return [$selectedPolicy, null];
}

/**
 * @param array<string,mixed> $cip25
 * @return array<string,mixed>
 */
function applyCompanionToV721(
    array $cip25,
    string $policyId,
    string $assetNameUtf8,
    string $assetNameHex,
    string $tokenId
): array {
    $status = trim((string) ($cip25['companion_status'] ?? ''));
    $txHash = strtolower(trim((string) ($cip25['companion_tx_hash'] ?? '')));
    $unit = strtolower(trim((string) (($cip25['companion_unit'] ?? '') ?: ($cip25['silver_shard_unit'] ?? ''))));
    $enabledRaw = $cip25['companion_enabled'] ?? null;
    $enabled = $enabledRaw === true || $enabledRaw === 1 || $enabledRaw === '1' || $enabledRaw === 'true';

    [$policyKey, $assetKey] = resolveV721PathKeys($cip25, $policyId, $assetNameUtf8, $assetNameHex, $tokenId);
    if ($policyKey === null || $assetKey === null) {
        return $cip25;
    }
    if (!isset($cip25['721']) || !is_array($cip25['721'])) {
        return $cip25;
    }

    $v721 = $cip25['721'];
    $policyBlock = $v721[$policyKey] ?? [];
    if (!is_array($policyBlock)) return $cip25;
    $assetMeta = $policyBlock[$assetKey] ?? [];
    if (!is_array($assetMeta)) $assetMeta = [];

    if ($status !== '') {
        $assetMeta['companion_status'] = $status;
    }
    if ($txHash !== '') {
        $assetMeta['companion_tx_hash'] = $txHash;
    }
    if ($unit !== '') {
        $assetMeta['companion_unit'] = $unit;
        $assetMeta['silver_shard_unit'] = $unit;
    }
    if ($enabled) {
        $assetMeta['companion_enabled'] = true;
    }

    $companion = $assetMeta['companion'] ?? [];
    if (!is_array($companion)) $companion = [];
    if ($enabled) $companion['enabled'] = true;
    if ($status !== '') $companion['status'] = $status;
    if ($txHash !== '') $companion['tx_hash'] = $txHash;
    if ($unit !== '') $companion['unit'] = $unit;
    $delivery = $companion['delivery'] ?? [];
    if (!is_array($delivery)) $delivery = [];
    if ($status !== '') $delivery['status'] = $status;
    if ($txHash !== '') $delivery['tx_hash'] = $txHash;
    $companion['delivery'] = $delivery;
    $assetMeta['companion'] = $companion;

    $policyBlock[$assetKey] = $assetMeta;
    $v721[$policyKey] = $policyBlock;
    $cip25['721'] = $v721;

    return $cip25;
}

Config::load(dirname(__DIR__) . '/.env');
$pdo = Db::pdo();

$cliTokens = array_values(array_filter(array_slice($argv, 1), static function ($v): bool {
    return is_string($v) && trim($v) !== '';
}));
$tokens = !empty($cliTokens)
    ? $cliTokens
    : [
        'qd-silver-0000705',
        'qd-silver-0000706',
        'qd-silver-0000707',
        'qd-silver-0000708',
        'qd-silver-0000709',
        'qd-silver-0000710',
        'qd-silver-0000711',
        'qd-silver-0000712',
    ];

$placeholders = implode(',', array_fill(0, count($tokens), '?'));
$sel = $pdo->prepare(
    "SELECT id, rarefolio_token_id, policy_id, asset_name_hex, asset_name_utf8, cip25_json
     FROM qd_tokens
     WHERE rarefolio_token_id IN ($placeholders)
     ORDER BY rarefolio_token_id ASC"
);
$sel->execute($tokens);
$rows = $sel->fetchAll(PDO::FETCH_ASSOC);

$upd = $pdo->prepare('UPDATE qd_tokens SET cip25_json = ?, updated_at = NOW() WHERE id = ? LIMIT 1');
$updated = [];
$skipped = [];

foreach ($rows as $row) {
    $tokenId = (string) ($row['rarefolio_token_id'] ?? '');
    $raw = (string) ($row['cip25_json'] ?? '');
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        $skipped[] = ['token_id' => $tokenId, 'reason' => 'cip25_missing_or_invalid'];
        continue;
    }

    $hasRootSignal = isset($decoded['companion_status']) || isset($decoded['companion_tx_hash']) || isset($decoded['companion_enabled']);
    if (!$hasRootSignal) {
        $skipped[] = ['token_id' => $tokenId, 'reason' => 'no_root_companion_fields'];
        continue;
    }

    $before = json_encode($decoded, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $patched = applyCompanionToV721(
        $decoded,
        (string) ($row['policy_id'] ?? ''),
        (string) ($row['asset_name_utf8'] ?? ''),
        (string) ($row['asset_name_hex'] ?? ''),
        $tokenId
    );
    $after = json_encode($patched, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (!is_string($before) || !is_string($after)) {
        $skipped[] = ['token_id' => $tokenId, 'reason' => 'json_encode_failed'];
        continue;
    }
    if ($before === $after) {
        $skipped[] = ['token_id' => $tokenId, 'reason' => 'already_synced'];
        continue;
    }

    $upd->execute([$after, (int) $row['id']]);
    $updated[] = $tokenId;
}

echo json_encode([
    'ok' => true,
    'target_count' => count($tokens),
    'fetched_count' => count($rows),
    'updated_count' => count($updated),
    'updated_tokens' => $updated,
    'skipped' => $skipped,
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
