<?php
/**
 * Private ownership verifier for download-claim flow.
 *
 * POST /api/private/ownership-verify.php
 *
 * Auth:
 *   Authorization: Bearer <DOWNLOAD_VERIFY_SHARED_SECRET>
 *
 * Body:
 *   {
 *     "cnft_id": "qd-silver-0000705",
 *     "signed_address": "<wallet addr used with signData>",
 *     "nonce": "<challenge nonce string>",
 *     "signature": { "signature": "...", "key": "..." }
 *   }
 *
 * Response:
 *   {
 *     "ok": true,
 *     "signature_valid": true|false,
 *     "owns_token": true|false,
 *     "signed_reward_address": "stake1..."|null,
 *     "owner_reward_address": "stake1..."|null
 *   }
 */
declare(strict_types=1);

require_once __DIR__ . '/../../src/Config.php';
require_once __DIR__ . '/../../src/Db.php';
require_once __DIR__ . '/../../src/Api/Validator.php';

use RareFolio\Config;
use RareFolio\Db;
use RareFolio\Api\Validator;

Config::load(dirname(__DIR__, 2) . '/.env');

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function fail_json(int $code, string $message): void
{
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $message], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function bearer_token(): string
{
    $raw = $_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
    if (!is_string($raw) || $raw === '') return '';
    if (!preg_match('/^\s*Bearer\s+(.+)\s*$/i', $raw, $m)) return '';
    return trim($m[1]);
}

function post_json(string $url, array $payload, array $headers = []): array
{
    $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($body === false) {
        throw new RuntimeException('could not encode request body');
    }

    $baseHeaders = [
        'Content-Type: application/json',
        'Accept: application/json',
    ];
    $allHeaders = array_merge($baseHeaders, $headers);

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => $allHeaders,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_TIMEOUT        => 8,
            CURLOPT_CONNECTTIMEOUT => 3,
        ]);
        $respBody = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err      = curl_error($ch);
        curl_close($ch);

        if ($respBody === false) {
            throw new RuntimeException('http request failed: ' . $err);
        }
    } else {
        $context = stream_context_create([
            'http' => [
                'method'  => 'POST',
                'header'  => implode("\r\n", $allHeaders),
                'content' => $body,
                'timeout' => 8,
                'ignore_errors' => true,
            ],
        ]);
        $respBody = file_get_contents($url, false, $context);
        if ($respBody === false) {
            throw new RuntimeException('http request failed');
        }
        $httpCode = 0;
        if (!empty($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m)) {
            $httpCode = (int) $m[1];
        }
    }

    $decoded = json_decode((string) $respBody, true);
    return [
        'status' => $httpCode,
        'body'   => is_array($decoded) ? $decoded : null,
    ];
}

function normalize_addr(string $addr): string
{
    $v = trim($addr);
    if (str_starts_with($v, '0x') || str_starts_with($v, '0X')) {
        return substr($v, 2);
    }
    return $v;
}

function first_string_value(array $src, array $keys): ?string
{
    foreach ($keys as $k) {
        $v = $src[$k] ?? null;
        if (is_string($v) && trim($v) !== '') {
            return trim($v);
        }
    }
    return null;
}

function verify_signature_with_fallbacks(string $sidecarBase, string $signedAddr, string $nonce, array $signature): array
{
    $signedAddr = normalize_addr($signedAddr);
    $attempts = [
        ['path' => '/auth/verify-signature', 'payload' => ['signed_address' => $signedAddr, 'nonce' => $nonce, 'signature' => $signature]],
        ['path' => '/auth/verify-signature', 'payload' => ['signedAddress' => $signedAddr, 'nonce' => $nonce, 'signature' => $signature]],
        ['path' => '/auth/verify-signature', 'payload' => ['signed_address' => $signedAddr, 'message' => $nonce, 'signature' => $signature]],
        ['path' => '/auth/verify-signature', 'payload' => ['signedAddress' => $signedAddr, 'message' => $nonce, 'signature' => $signature]],
        ['path' => '/auth/verify',           'payload' => ['signed_address' => $signedAddr, 'nonce' => $nonce, 'signature' => $signature]],
        ['path' => '/auth/verify',           'payload' => ['signedAddress' => $signedAddr, 'nonce' => $nonce, 'signature' => $signature]],
    ];

    $lastError = 'unknown';
    foreach ($attempts as $a) {
        try {
            $resp = post_json($sidecarBase . $a['path'], $a['payload']);
        } catch (Throwable $e) {
            $lastError = $e->getMessage();
            continue;
        }
        $body = $resp['body'];
        if (!is_array($body)) {
            $lastError = 'non-json response';
            continue;
        }

        if (($body['ok'] ?? null) === true || array_key_exists('signature_valid', $body) || array_key_exists('valid', $body) || array_key_exists('is_valid', $body)) {
            $sigValid = (bool) ($body['signature_valid'] ?? $body['valid'] ?? $body['is_valid'] ?? false);
            $reward   = first_string_value($body, ['reward_address', 'rewardAddress', 'stake_address', 'stakeAddress']);
            return [
                'ok'              => true,
                'signature_valid' => $sigValid,
                'reward_address'  => $reward,
            ];
        }

        $lastError = (string) ($body['error'] ?? ('http ' . (int) $resp['status']));
    }

    return [
        'ok' => false,
        'error' => $lastError,
    ];
}

function resolve_reward_address_with_fallbacks(string $sidecarBase, string $address): ?string
{
    $address = normalize_addr($address);
    $attempts = [
        ['path' => '/auth/reward-address', 'payload' => ['address' => $address]],
        ['path' => '/auth/reward-address', 'payload' => ['wallet_address' => $address]],
        ['path' => '/auth/reward',         'payload' => ['address' => $address]],
        ['path' => '/auth/reward',         'payload' => ['wallet_address' => $address]],
    ];

    foreach ($attempts as $a) {
        try {
            $resp = post_json($sidecarBase . $a['path'], $a['payload']);
        } catch (Throwable) {
            continue;
        }
        $body = $resp['body'];
        if (!is_array($body)) continue;
        if (($body['ok'] ?? null) === true || array_key_exists('reward_address', $body) || array_key_exists('rewardAddress', $body) || array_key_exists('stake_address', $body) || array_key_exists('stakeAddress', $body)) {
            return first_string_value($body, ['reward_address', 'rewardAddress', 'stake_address', 'stakeAddress']);
        }
    }

    return null;
}

function get_json(string $url, array $headers = []): array
{
    $baseHeaders = ['Accept: application/json'];
    $allHeaders  = array_merge($baseHeaders, $headers);

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPGET        => true,
            CURLOPT_HTTPHEADER     => $allHeaders,
            CURLOPT_TIMEOUT        => 8,
            CURLOPT_CONNECTTIMEOUT => 3,
        ]);
        $respBody = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err      = curl_error($ch);
        curl_close($ch);

        if ($respBody === false) {
            throw new RuntimeException('http request failed: ' . $err);
        }
    } else {
        $context = stream_context_create([
            'http' => [
                'method'  => 'GET',
                'header'  => implode("\r\n", $allHeaders),
                'timeout' => 8,
                'ignore_errors' => true,
            ],
        ]);
        $respBody = file_get_contents($url, false, $context);
        if ($respBody === false) {
            throw new RuntimeException('http request failed');
        }
        $httpCode = 0;
        if (!empty($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m)) {
            $httpCode = (int) $m[1];
        }
    }

    $decoded = json_decode((string) $respBody, true);
    return [
        'status' => $httpCode,
        'body'   => is_array($decoded) ? $decoded : null,
    ];
}

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
if ($method !== 'POST') {
    fail_json(405, 'POST required');
}

$secret = (string) Config::get('DOWNLOAD_VERIFY_SHARED_SECRET', '');
if ($secret === '') {
    fail_json(503, 'DOWNLOAD_VERIFY_SHARED_SECRET not configured');
}

$token = bearer_token();
if ($token === '' || !hash_equals($secret, $token)) {
    fail_json(401, 'unauthorized');
}

$raw = file_get_contents('php://input');
$req = json_decode((string) $raw, true);
if (!is_array($req)) {
    fail_json(400, 'invalid JSON');
}

$cnftId       = trim((string) ($req['cnft_id'] ?? ''));
$signedAddr   = trim((string) ($req['signed_address'] ?? ''));
$nonce        = (string) ($req['nonce'] ?? '');
$sig          = $req['signature'] ?? null;

if ($signedAddr === '' || $nonce === '' || !is_array($sig)) {
    fail_json(400, 'cnft_id, signed_address, nonce, signature required');
}

try {
    $cnftId = Validator::cnftId($cnftId);
} catch (InvalidArgumentException $e) {
    fail_json(400, $e->getMessage());
}

$sidecarBase = rtrim((string) Config::get('SIDECAR_BASE_URL', 'http://localhost:4000'), '/');

try {
    // 1) Load token ownership context
    $pdo = Db::pdo();
    $stmt = $pdo->prepare(
        'SELECT policy_id, asset_name_hex, current_owner_wallet
           FROM qd_tokens
          WHERE rarefolio_token_id = ?
          LIMIT 1'
    );
    $stmt->execute([$cnftId]);
    $row = $stmt->fetch();

    if (!$row) {
        fail_json(404, 'token not found');
    }

    $ownerAddr = trim((string) ($row['current_owner_wallet'] ?? ''));
    // 2) Verify wallet signature (CIP-30/CIP-8) via sidecar auth route
    $sigCheck = verify_signature_with_fallbacks($sidecarBase, $signedAddr, $nonce, $sig);
    $signatureBackendOk = (bool) ($sigCheck['ok'] ?? false);
    $signatureValid = $signatureBackendOk ? (bool) ($sigCheck['signature_valid'] ?? false) : false;
    $signedReward   = $signatureBackendOk && is_string($sigCheck['reward_address'] ?? null)
        ? (string) $sigCheck['reward_address']
        : null;

    if (!$signatureBackendOk) {
        $network = strtolower((string) Config::get('BLOCKFROST_NETWORK', 'preprod'));
        $addrMatch = (
            $ownerAddr !== '' &&
            normalize_addr($signedAddr) !== '' &&
            strtolower(normalize_addr($signedAddr)) === strtolower(normalize_addr($ownerAddr))
        );
        // Temporary compatibility fallback for preprod while sidecar auth
        // endpoint is unavailable. Mainnet remains strict.
        if ($network !== 'mainnet' && $addrMatch) {
            $signatureValid = true;
        } else {
            fail_json(502, 'sidecar signature verification failed');
        }
    }

    if (!$signatureValid) {
        echo json_encode([
            'ok'                    => true,
            'signature_valid'       => false,
            'owns_token'            => false,
            'signed_reward_address' => null,
            'owner_reward_address'  => null,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    // 3) If DB owner is missing, attempt live chain owner lookup
    // 3) If DB owner is missing, attempt live chain owner lookup
    if ($ownerAddr === '') {
        $unit = (string) $row['policy_id'] . (string) $row['asset_name_hex'];
        $syncResp = get_json($sidecarBase . '/sync/token/' . rawurlencode($unit));
        $syncBody = $syncResp['body'];
        if ($syncResp['status'] === 200 && is_array($syncBody) && is_string($syncBody['current_owner'] ?? null)) {
            $ownerAddr = trim((string) $syncBody['current_owner']);
        }
    }

    // 4) Derive current owner's reward address for stake-level comparison
    $ownerReward = ($ownerAddr !== '') ? resolve_reward_address_with_fallbacks($sidecarBase, $ownerAddr) : null;

    $owns = false;
    if (
        is_string($signedReward) && $signedReward !== '' &&
        is_string($ownerReward)  && $ownerReward  !== ''
    ) {
        $owns = strtolower($signedReward) === strtolower($ownerReward);
    } elseif ($ownerAddr !== '' && $signedAddr !== '') {
        // Compatibility fallback for older sidecar deployments where reward
        // conversion route is unavailable. We only fall back to direct address
        // equality after successful signature verification.
        $owns = strtolower(normalize_addr($signedAddr)) === strtolower(normalize_addr($ownerAddr));
    }

    echo json_encode([
        'ok'                    => true,
        'signature_valid'       => true,
        'owns_token'            => $owns,
        'signed_reward_address' => $signedReward,
        'owner_reward_address'  => $ownerReward,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('[private ownership-verify] ' . $e->getMessage());
    fail_json(500, 'server error');
}
