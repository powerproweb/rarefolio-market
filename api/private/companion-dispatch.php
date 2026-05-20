<?php
/**
 * Private companion dispatch endpoint for bounded ops batches.
 *
 * POST /api/private/companion-dispatch.php
 *
 * Auth:
 *   Authorization: Bearer <COMPANION_OPS_SHARED_SECRET>
 *   fallback headers: X-Companion-Ops-Secret or X-Deploy-Secret
 *
 * Body:
 *   {
 *     "order_ids": [123,124],                 // optional
 *     "token_ids": ["qd-silver-0000705"],     // optional
 *     "treasury_env_key": "FOUNDERS",         // optional override
 *     "companion_unit": "<policy+asset_hex>", // optional override
 *     "submit": true,                         // optional, default true
 *     "check_treasury": true,                 // optional, default true
 *     "max_items": 20                         // optional, 1..50
 *   }
 *
 * Notes:
 * - At least one selector array is required (order_ids or token_ids).
 * - Batch size is hard bounded to avoid broad accidental dispatch.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../src/Config.php';
require_once __DIR__ . '/../../src/Db.php';
require_once __DIR__ . '/../../src/Sidecar/Client.php';

use RareFolio\Config;
use RareFolio\Db;
use RareFolio\Sidecar\Client as SidecarClient;

Config::load(dirname(__DIR__, 2) . '/.env');

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function fail_json(int $code, string $message): void
{
    http_response_code($code);
    echo json_encode(
        ['ok' => false, 'error' => $message],
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
    );
    exit;
}

function request_header_value(string $name): string
{
    $serverKey = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
    $candidates = [$serverKey, 'REDIRECT_' . $serverKey];
    foreach ($candidates as $key) {
        $value = $_SERVER[$key] ?? null;
        if (is_string($value) && trim($value) !== '') {
            return trim($value);
        }
    }

    if (function_exists('getallheaders')) {
        foreach ((array) getallheaders() as $headerName => $headerValue) {
            if (
                is_string($headerName)
                && strcasecmp($headerName, $name) === 0
                && is_string($headerValue)
                && trim($headerValue) !== ''
            ) {
                return trim($headerValue);
            }
        }
    }

    return '';
}

function request_shared_secret(): string
{
    $auth = request_header_value('Authorization');
    if ($auth !== '' && preg_match('/^\s*Bearer\s+(.+)\s*$/i', $auth, $m)) {
        return trim($m[1]);
    }

    $fallbacks = ['X-Companion-Ops-Secret', 'X-Deploy-Secret'];
    foreach ($fallbacks as $header) {
        $value = request_header_value($header);
        if ($value !== '') return $value;
    }

    return '';
}

/**
 * @return int[]
 */
function normalize_int_list(mixed $raw): array
{
    if (!is_array($raw)) return [];
    $out = [];
    foreach ($raw as $v) {
        $i = (int) $v;
        if ($i > 0) $out[$i] = $i;
    }
    return array_values($out);
}

/**
 * @return string[]
 */
function normalize_token_id_list(mixed $raw): array
{
    if (!is_array($raw)) return [];
    $out = [];
    foreach ($raw as $v) {
        $tokenId = trim((string) $v);
        if ($tokenId === '') continue;
        if (!preg_match('/^[a-z0-9\-]{3,64}$/i', $tokenId)) continue;
        $out[$tokenId] = $tokenId;
    }
    return array_values($out);
}

function normalize_env_key(string $raw): ?string
{
    $envKey = strtoupper(trim($raw));
    if ($envKey === '') return null;
    if (!preg_match('/^[A-Z0-9_]{1,64}$/', $envKey)) return null;
    return $envKey;
}

function normalize_unit(string $raw): ?string
{
    $unit = strtolower(trim($raw));
    if ($unit === '') return null;
    $unit = preg_replace('/^0x/i', '', $unit);
    if (!is_string($unit) || !preg_match('/^[0-9a-f]{56,}$/', $unit)) return null;
    return $unit;
}

function parse_quantity(string $raw): int
{
    $q = trim($raw);
    if ($q === '' || preg_match('/^\d+$/', $q) !== 1) return 0;
    if (strlen($q) > 18) return PHP_INT_MAX;
    $n = (int) $q;
    return $n > 0 ? $n : 0;
}

/**
 * @param array<string,mixed> $cip25
 */
function resolve_companion_unit(array $cip25): ?string
{
    $candidates = [
        $cip25['companion_unit'] ?? null,
        $cip25['silver_shard_unit'] ?? null,
        $cip25['companion']['unit'] ?? null,
        $cip25['attributes']['companion_unit'] ?? null,
        $cip25['attributes']['silver_shard_unit'] ?? null,
    ];
    foreach ($candidates as $candidate) {
        if (!is_string($candidate) || trim($candidate) === '') continue;
        $unit = normalize_unit($candidate);
        if ($unit !== null) return $unit;
    }
    return null;
}

/**
 * @return array<string,mixed>
 */
function decode_cip25_json(mixed $raw): array
{
    if (!is_string($raw) || trim($raw) === '') return [];
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}
/**
 * @param array<string,mixed> $cip25
 */
function companion_already_submitted(array $cip25): bool
{
    $statusCandidates = [
        $cip25['companion_status'] ?? null,
        $cip25['companion']['status'] ?? null,
        $cip25['companion']['delivery']['status'] ?? null,
    ];
    foreach ($statusCandidates as $status) {
        if (is_string($status) && strtolower(trim($status)) === 'submitted') {
            return true;
        }
    }

    $txCandidates = [
        $cip25['companion_tx_hash'] ?? null,
        $cip25['companion']['tx_hash'] ?? null,
        $cip25['companion']['delivery']['tx_hash'] ?? null,
    ];
    foreach ($txCandidates as $txHash) {
        if (is_string($txHash) && preg_match('/^[0-9a-f]{64}$/i', trim($txHash)) === 1) {
            return true;
        }
    }

    return false;
}

/**
 * @param array<string,mixed> $cip25
 * @return array{0: ?string, 1: ?string}
 */
function resolve_v721_path_keys(
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
        if (is_string($candidate) && $candidate !== '' && !in_array($candidate, $policyCandidates, true)) {
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
        $block = $v721[$policyCandidate] ?? null;
        if (is_array($block)) {
            $selectedPolicy = $policyCandidate;
            break;
        }
    }
    if ($selectedPolicy === null) return [null, null];

    $policyBlock = $v721[$selectedPolicy];
    $assetCandidates = [];
    foreach ([$assetNameUtf8, $tokenId, $assetNameHex, strtolower($assetNameHex), strtoupper($assetNameHex)] as $candidate) {
        if (is_string($candidate) && $candidate !== '' && !in_array($candidate, $assetCandidates, true)) {
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
function apply_companion_submission(
    array $cip25,
    string $txHash,
    ?string $unit = null,
    string $policyId = '',
    string $assetNameUtf8 = '',
    string $assetNameHex = '',
    string $tokenId = ''
): array {
    $txHash = strtolower($txHash);
    $unit = $unit !== null ? strtolower(trim($unit)) : null;
    $cip25['companion_enabled'] = true;
    $cip25['companion_status'] = 'submitted';
    $cip25['companion_tx_hash'] = $txHash;
    if ($unit !== null && $unit !== '') {
        $cip25['companion_unit'] = $unit;
        $cip25['silver_shard_unit'] = $unit;
    }

    $companion = $cip25['companion'] ?? [];
    if (!is_array($companion)) $companion = [];
    $companion['enabled'] = true;
    $companion['status'] = 'submitted';
    $companion['tx_hash'] = $txHash;
    if ($unit !== null && $unit !== '') {
        $companion['unit'] = $unit;
    }

    $delivery = $companion['delivery'] ?? [];
    if (!is_array($delivery)) $delivery = [];
    $delivery['status'] = 'submitted';
    $delivery['tx_hash'] = $txHash;
    $companion['delivery'] = $delivery;

    $cip25['companion'] = $companion;

    [$policyKey, $assetKey] = resolve_v721_path_keys($cip25, $policyId, $assetNameUtf8, $assetNameHex, $tokenId);
    if ($policyKey !== null && $assetKey !== null && isset($cip25['721']) && is_array($cip25['721'])) {
        $v721 = $cip25['721'];
        $policyBlock = $v721[$policyKey] ?? [];
        if (is_array($policyBlock)) {
            $assetMeta = $policyBlock[$assetKey] ?? [];
            if (!is_array($assetMeta)) $assetMeta = [];
            $assetMeta['companion_enabled'] = true;
            $assetMeta['companion_status'] = 'submitted';
            $assetMeta['companion_tx_hash'] = $txHash;
            if ($unit !== null && $unit !== '') {
                $assetMeta['companion_unit'] = $unit;
                $assetMeta['silver_shard_unit'] = $unit;
            }

            $assetCompanion = $assetMeta['companion'] ?? [];
            if (!is_array($assetCompanion)) $assetCompanion = [];
            $assetCompanion['enabled'] = true;
            $assetCompanion['status'] = 'submitted';
            $assetCompanion['tx_hash'] = $txHash;
            if ($unit !== null && $unit !== '') {
                $assetCompanion['unit'] = $unit;
            }
            $assetDelivery = $assetCompanion['delivery'] ?? [];
            if (!is_array($assetDelivery)) $assetDelivery = [];
            $assetDelivery['status'] = 'submitted';
            $assetDelivery['tx_hash'] = $txHash;
            $assetCompanion['delivery'] = $assetDelivery;
            $assetMeta['companion'] = $assetCompanion;

            $policyBlock[$assetKey] = $assetMeta;
            $v721[$policyKey] = $policyBlock;
            $cip25['721'] = $v721;
        }
    }

    return $cip25;
}

/**
 * @return array<int,array<string,mixed>>
 */
function fetch_targets(PDO $pdo, array $orderIds, array $tokenIds, int $maxItems): array
{
    $targetsByToken = [];

    if (!empty($orderIds)) {
        $placeholders = implode(',', array_fill(0, count($orderIds), '?'));
        $stmt = $pdo->prepare(
            "SELECT o.id AS order_id,
                    o.status AS order_status,
                    o.buyer_addr,
                    t.id AS token_row_id,
                    t.rarefolio_token_id,
                    t.collection_slug,
                    t.policy_id,
                    t.asset_name_hex,
                    t.asset_name_utf8,
                    t.cip25_json,
                    t.current_owner_wallet,
                    t.custody_status,
                    t.primary_sale_status,
                    c.policy_env_key
             FROM qd_orders o
             JOIN qd_tokens t ON t.rarefolio_token_id = o.rarefolio_token_id
             LEFT JOIN qd_collections c ON c.slug = t.collection_slug
             WHERE o.id IN ($placeholders)
             ORDER BY o.id DESC"
        );
        $stmt->execute($orderIds);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $tokenId = (string) ($row['rarefolio_token_id'] ?? '');
            if ($tokenId === '') continue;
            $targetsByToken[$tokenId] = $row;
        }
    }

    if (!empty($tokenIds)) {
        $placeholders = implode(',', array_fill(0, count($tokenIds), '?'));
        $stmt = $pdo->prepare(
            "SELECT t.id AS token_row_id,
                    t.rarefolio_token_id,
                    t.collection_slug,
                    t.policy_id,
                    t.asset_name_hex,
                    t.asset_name_utf8,
                    t.cip25_json,
                    t.current_owner_wallet,
                    t.custody_status,
                    t.primary_sale_status,
                    c.policy_env_key,
                    o.id AS order_id,
                    o.status AS order_status,
                    o.buyer_addr
             FROM qd_tokens t
             LEFT JOIN qd_collections c ON c.slug = t.collection_slug
             LEFT JOIN qd_orders o ON o.id = (
                SELECT oo.id
                FROM qd_orders oo
                WHERE oo.rarefolio_token_id = t.rarefolio_token_id
                  AND oo.status IN ('settled','submitted')
                ORDER BY (oo.status = 'settled') DESC, oo.id DESC
                LIMIT 1
             )
             WHERE t.rarefolio_token_id IN ($placeholders)
             ORDER BY t.id DESC"
        );
        $stmt->execute($tokenIds);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $tokenId = (string) ($row['rarefolio_token_id'] ?? '');
            if ($tokenId === '') continue;
            if (!isset($targetsByToken[$tokenId])) {
                $targetsByToken[$tokenId] = $row;
            }
        }
    }

    return array_slice(array_values($targetsByToken), 0, $maxItems);
}

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
if ($method !== 'POST') fail_json(405, 'POST required');

$secret = (string) Config::get(
    'COMPANION_OPS_SHARED_SECRET',
    (string) Config::get('DEPLOY_WEBHOOK_SECRET', '')
);
if ($secret === '') {
    fail_json(503, 'COMPANION_OPS_SHARED_SECRET not configured');
}
$providedSecret = request_shared_secret();
if ($providedSecret === '' || !hash_equals($secret, $providedSecret)) {
    fail_json(401, 'unauthorized');
}

$raw = file_get_contents('php://input');
$body = json_decode((string) $raw, true);
if (!is_array($body)) $body = [];

$orderIds = normalize_int_list($body['order_ids'] ?? []);
$tokenIds = normalize_token_id_list($body['token_ids'] ?? []);
if (empty($orderIds) && empty($tokenIds)) {
    fail_json(400, 'order_ids or token_ids is required');
}

$maxItems = (int) ($body['max_items'] ?? 20);
$maxItems = max(1, min(50, $maxItems));
if ((count($orderIds) + count($tokenIds)) > $maxItems) {
    fail_json(400, 'requested selectors exceed max_items bound');
}

$submit = !array_key_exists('submit', $body) || (bool) $body['submit'];
$checkTreasury = !array_key_exists('check_treasury', $body) || (bool) $body['check_treasury'];

$overrideEnvKey = normalize_env_key((string) ($body['treasury_env_key'] ?? ''));
if (($body['treasury_env_key'] ?? null) !== null && $overrideEnvKey === null) {
    fail_json(400, 'treasury_env_key is invalid');
}

$overrideUnit = normalize_unit((string) ($body['companion_unit'] ?? ''));
if (($body['companion_unit'] ?? null) !== null && $overrideUnit === null) {
    fail_json(400, 'companion_unit is invalid');
}

try {
    $pdo = Db::pdo();
    $targets = fetch_targets($pdo, $orderIds, $tokenIds, $maxItems);
    $sidecar = new SidecarClient();

    $treasuryChecks = [];
    if ($checkTreasury) {
        $keys = [];
        foreach ($targets as $target) {
            $envKey = $overrideEnvKey ?? normalize_env_key((string) ($target['policy_env_key'] ?? ''));
            if ($envKey !== null) $keys[$envKey] = true;
        }
        foreach (array_keys($keys) as $envKey) {
            try {
                $treasuryChecks[$envKey] = [
                    'ok' => true,
                    'balance' => $sidecar->getCompanionTreasuryBalance($envKey),
                ];
            } catch (Throwable $e) {
                $treasuryChecks[$envKey] = [
                    'ok' => false,
                    'error' => $e->getMessage(),
                ];
            }
        }
    }

    $updateTokenStmt = $pdo->prepare(
        'UPDATE qd_tokens SET cip25_json = ?, updated_at = NOW() WHERE id = ? LIMIT 1'
    );

    $results = [];
    /** @var array<int,array<string,mixed>> $preparedTargets */
    $preparedTargets = [];
    /** @var array<string,int> $groupRequired */
    $groupRequired = [];
    /** @var array<string,array{env_key:string,unit:string}> $groupMeta */
    $groupMeta = [];
    /** @var array<string,array{ok:bool,available:int,raw_quantity:string,error?:string,required:int,env_key:string,unit:string}> $inventoryState */
    $inventoryState = [];
    $submittedCount = 0;
    $failedCount = 0;
    $skippedCount = 0;

    foreach ($targets as $target) {
        $tokenId = (string) ($target['rarefolio_token_id'] ?? '');
        $tokenRowId = (int) ($target['token_row_id'] ?? 0);
        $orderId = (int) ($target['order_id'] ?? 0);
        $orderStatus = strtolower(trim((string) ($target['order_status'] ?? '')));
        $buyerAddr = trim((string) ($target['buyer_addr'] ?? ''));
        $recipientAddr = '';
        $recipientSource = '';
        if ($buyerAddr !== '' && in_array($orderStatus, ['submitted', 'settled'], true)) {
            $recipientAddr = $buyerAddr;
            $recipientSource = 'order';
        }
        $envKey = $overrideEnvKey ?? normalize_env_key((string) ($target['policy_env_key'] ?? ''));
        $cip25 = decode_cip25_json($target['cip25_json'] ?? null);
        $unit = $overrideUnit ?? resolve_companion_unit($cip25);
        $policyId = strtolower(trim((string) ($target['policy_id'] ?? '')));
        $assetNameHex = strtolower(trim((string) ($target['asset_name_hex'] ?? '')));
        $nftUnit = null;
        if (
            preg_match('/^[0-9a-f]{56}$/', $policyId) === 1
            && preg_match('/^[0-9a-f]+$/', $assetNameHex) === 1
        ) {
            $nftUnit = $policyId . $assetNameHex;
        }

        if ($tokenId === '' || $tokenRowId <= 0) {
            $failedCount++;
            $results[] = [
                'ok' => false,
                'token_id' => $tokenId,
                'order_id' => $orderId,
                'reason' => 'token_missing',
            ];
            continue;
        }
        if ($orderId <= 0 || !in_array($orderStatus, ['submitted', 'settled'], true) || $buyerAddr === '') {
            $skippedCount++;
            $results[] = [
                'ok' => false,
                'token_id' => $tokenId,
                'order_id' => $orderId > 0 ? $orderId : null,
                'reason' => 'strict_pair_requires_dispatchable_order',
                'order_status' => $orderStatus,
            ];
            continue;
        }
        if ($recipientAddr === '') {
            $skippedCount++;
            $results[] = [
                'ok' => false,
                'token_id' => $tokenId,
                'order_id' => $orderId,
                'reason' => 'recipient_missing',
            ];
            continue;
        }
        if ($envKey === null) {
            $skippedCount++;
            $results[] = [
                'ok' => false,
                'token_id' => $tokenId,
                'order_id' => $orderId,
                'reason' => 'treasury_env_key_missing',
            ];
            continue;
        }
        if ($unit === null) {
            $skippedCount++;
            $results[] = [
                'ok' => false,
                'token_id' => $tokenId,
                'order_id' => $orderId,
                'reason' => 'companion_unit_missing',
            ];
            continue;
        }
        if ($nftUnit === null) {
            $skippedCount++;
            $results[] = [
                'ok' => false,
                'token_id' => $tokenId,
                'order_id' => $orderId,
                'reason' => 'nft_unit_missing',
            ];
            continue;
        }
        if (companion_already_submitted($cip25)) {
            $skippedCount++;
            $results[] = [
                'ok' => false,
                'token_id' => $tokenId,
                'order_id' => $orderId,
                'reason' => 'companion_already_submitted',
            ];
            continue;
        }

        $companionInventoryKey = $envKey . '|COMPANION|' . $unit;
        $nftInventoryKey = $envKey . '|NFT|' . $nftUnit;

        $preparedTargets[] = [
            'target' => $target,
            'token_id' => $tokenId,
            'token_row_id' => $tokenRowId,
            'order_id' => $orderId,
            'order_status' => $orderStatus,
            'recipient_addr' => $recipientAddr,
            'recipient_source' => $recipientSource !== '' ? $recipientSource : null,
            'treasury_env_key' => $envKey,
            'unit' => $unit,
            'nft_unit' => $nftUnit,
            'companion_inventory_key' => $companionInventoryKey,
            'nft_inventory_key' => $nftInventoryKey,
            'cip25' => $cip25,
            'policy_id' => (string) ($target['policy_id'] ?? ''),
            'asset_name_hex' => (string) ($target['asset_name_hex'] ?? ''),
            'asset_name_utf8' => (string) ($target['asset_name_utf8'] ?? ''),
        ];

        if ($submit) {
            $groupRequired[$companionInventoryKey] = (int) (($groupRequired[$companionInventoryKey] ?? 0) + 1);
            if (!isset($groupMeta[$companionInventoryKey])) {
                $groupMeta[$companionInventoryKey] = ['env_key' => $envKey, 'unit' => $unit];
            }
            $groupRequired[$nftInventoryKey] = (int) (($groupRequired[$nftInventoryKey] ?? 0) + 1);
            if (!isset($groupMeta[$nftInventoryKey])) {
                $groupMeta[$nftInventoryKey] = ['env_key' => $envKey, 'unit' => $nftUnit];
            }
        }
    }

    if ($submit) {
        foreach ($groupRequired as $inventoryKey => $required) {
            $meta = $groupMeta[$inventoryKey] ?? null;
            if ($meta === null) continue;
            $envKey = $meta['env_key'];
            $unit = $meta['unit'];

            if (!isset($treasuryChecks[$envKey]) || !is_array($treasuryChecks[$envKey])) {
                $treasuryChecks[$envKey] = ['ok' => true];
            }
            if (!isset($treasuryChecks[$envKey]['unit_balances']) || !is_array($treasuryChecks[$envKey]['unit_balances'])) {
                $treasuryChecks[$envKey]['unit_balances'] = [];
            }

            try {
                $unitBalance = $sidecar->getCompanionTreasuryUnitBalance($envKey, $unit);
                $rawQty = (string) ($unitBalance['quantity'] ?? '0');
                $available = parse_quantity($rawQty);
                $inventoryState[$inventoryKey] = [
                    'ok' => true,
                    'available' => $available,
                    'raw_quantity' => $rawQty,
                    'required' => $required,
                    'env_key' => $envKey,
                    'unit' => $unit,
                ];
                $treasuryChecks[$envKey]['unit_balances'][$unit] = $unitBalance;
            } catch (Throwable $e) {
                $inventoryState[$inventoryKey] = [
                    'ok' => false,
                    'available' => 0,
                    'raw_quantity' => '0',
                    'error' => $e->getMessage(),
                    'required' => $required,
                    'env_key' => $envKey,
                    'unit' => $unit,
                ];
                $treasuryChecks[$envKey]['unit_balances'][$unit] = [
                    'ok' => false,
                    'error' => $e->getMessage(),
                ];
            }
        }
    }

    foreach ($preparedTargets as $prepared) {
        $tokenId = (string) ($prepared['token_id'] ?? '');
        $tokenRowId = (int) ($prepared['token_row_id'] ?? 0);
        $orderId = (int) ($prepared['order_id'] ?? 0);
        $orderStatus = (string) ($prepared['order_status'] ?? '');
        $recipientAddr = (string) ($prepared['recipient_addr'] ?? '');
        $recipientSource = $prepared['recipient_source'] ?? null;
        $envKey = (string) ($prepared['treasury_env_key'] ?? '');
        $unit = (string) ($prepared['unit'] ?? '');
        $nftUnit = (string) ($prepared['nft_unit'] ?? '');
        $companionInventoryKey = (string) ($prepared['companion_inventory_key'] ?? '');
        $nftInventoryKey = (string) ($prepared['nft_inventory_key'] ?? '');
        $cip25 = is_array($prepared['cip25'] ?? null) ? $prepared['cip25'] : [];
        $policyId = (string) ($prepared['policy_id'] ?? '');
        $assetNameHex = (string) ($prepared['asset_name_hex'] ?? '');
        $assetNameUtf8 = (string) ($prepared['asset_name_utf8'] ?? '');

        if ($submit) {
            $companionInventory = $inventoryState[$companionInventoryKey] ?? null;
            if (!is_array($companionInventory) || (($companionInventory['ok'] ?? false) !== true)) {
                $failedCount++;
                $results[] = [
                    'ok' => false,
                    'token_id' => $tokenId,
                    'order_id' => $orderId > 0 ? $orderId : null,
                    'reason' => 'companion_inventory_check_failed',
                    'treasury_env_key' => $envKey,
                    'companion_unit' => $unit,
                    'error' => (string) ($companionInventory['error'] ?? 'inventory check unavailable'),
                ];
                continue;
            }
            $nftInventory = $inventoryState[$nftInventoryKey] ?? null;
            if (!is_array($nftInventory) || (($nftInventory['ok'] ?? false) !== true)) {
                $failedCount++;
                $results[] = [
                    'ok' => false,
                    'token_id' => $tokenId,
                    'order_id' => $orderId > 0 ? $orderId : null,
                    'reason' => 'nft_inventory_check_failed',
                    'treasury_env_key' => $envKey,
                    'nft_unit' => $nftUnit,
                    'error' => (string) ($nftInventory['error'] ?? 'inventory check unavailable'),
                ];
                continue;
            }
            $companionAvailable = (int) ($companionInventory['available'] ?? 0);
            $companionRequired = (int) ($companionInventory['required'] ?? 0);
            if ($companionAvailable < 1) {
                $skippedCount++;
                $results[] = [
                    'ok' => false,
                    'token_id' => $tokenId,
                    'order_id' => $orderId > 0 ? $orderId : null,
                    'reason' => 'companion_inventory_insufficient',
                    'treasury_env_key' => $envKey,
                    'companion_unit' => $unit,
                    'required_for_batch' => $companionRequired,
                    'available' => (int) ($companionInventory['available'] ?? 0),
                    'available_raw' => (string) ($companionInventory['raw_quantity'] ?? '0'),
                ];
                continue;
            }
            $nftAvailable = (int) ($nftInventory['available'] ?? 0);
            $nftRequired = (int) ($nftInventory['required'] ?? 0);
            if ($nftAvailable < 1) {
                $skippedCount++;
                $results[] = [
                    'ok' => false,
                    'token_id' => $tokenId,
                    'order_id' => $orderId > 0 ? $orderId : null,
                    'reason' => 'nft_inventory_insufficient',
                    'treasury_env_key' => $envKey,
                    'nft_unit' => $nftUnit,
                    'required_for_batch' => $nftRequired,
                    'available' => (int) ($nftInventory['available'] ?? 0),
                    'available_raw' => (string) ($nftInventory['raw_quantity'] ?? '0'),
                ];
                continue;
            }
            $inventoryState[$companionInventoryKey]['available'] = $companionAvailable - 1;
            $inventoryState[$nftInventoryKey]['available'] = $nftAvailable - 1;
        }

        try {
            $sidecarResponse = $sidecar->transferPairedAsset([
                'treasury_env_key' => $envKey,
                'recipient_addr' => $recipientAddr,
                'nft_unit' => $nftUnit,
                'companion_unit' => $unit,
                'companion_quantity' => 1,
                'submit' => $submit,
            ]);

            $txHash = trim((string) ($sidecarResponse['tx_hash'] ?? ''));
            $submitted = (bool) ($sidecarResponse['submitted'] ?? false);

            if ($submit && $submitted && preg_match('/^[0-9a-f]{64}$/i', $txHash) === 1) {
                $cip25 = apply_companion_submission(
                    $cip25,
                    $txHash,
                    $unit,
                    $policyId,
                    $assetNameUtf8,
                    $assetNameHex,
                    $tokenId
                );
                $encoded = json_encode($cip25, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                if ($encoded !== false) {
                    $updateTokenStmt->execute([$encoded, $tokenRowId]);
                }
            }

            $submittedCount++;
            $results[] = [
                'ok' => true,
                'token_id' => $tokenId,
                'order_id' => $orderId > 0 ? $orderId : null,
                'order_status' => $orderStatus !== '' ? $orderStatus : null,
                'treasury_env_key' => $envKey,
                'recipient_addr' => $recipientAddr,
                'recipient_source' => $recipientSource,
                'nft_unit' => $nftUnit,
                'companion_unit' => $unit,
                'sidecar' => $sidecarResponse,
            ];
        } catch (Throwable $e) {
            if ($submit && isset($inventoryState[$companionInventoryKey]) && is_array($inventoryState[$companionInventoryKey])) {
                $inventoryState[$companionInventoryKey]['available'] = (int) (($inventoryState[$companionInventoryKey]['available'] ?? 0) + 1);
            }
            if ($submit && isset($inventoryState[$nftInventoryKey]) && is_array($inventoryState[$nftInventoryKey])) {
                $inventoryState[$nftInventoryKey]['available'] = (int) (($inventoryState[$nftInventoryKey]['available'] ?? 0) + 1);
            }
            $failedCount++;
            $results[] = [
                'ok' => false,
                'token_id' => $tokenId,
                'order_id' => $orderId > 0 ? $orderId : null,
                'reason' => 'sidecar_transfer_failed',
                'nft_unit' => $nftUnit,
                'companion_unit' => $unit,
                'error' => $e->getMessage(),
            ];
        }
    }

    echo json_encode(
        [
            'ok' => true,
            'submit' => $submit,
            'selected_count' => count($targets),
            'submitted_count' => $submittedCount,
            'failed_count' => $failedCount,
            'skipped_count' => $skippedCount,
            'treasury_checks' => $treasuryChecks,
            'results' => $results,
        ],
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
    );
} catch (Throwable $e) {
    error_log('[companion-dispatch] ' . $e->getMessage());
    fail_json(500, 'server error');
}
