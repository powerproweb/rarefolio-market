<?php
/**
 * Public payment-prepare endpoint.
 *
 * POST /api/payment-prepare.php
 *
 * The buy page ("Connect & Pay with Browser Wallet") calls this to get an
 * UNSIGNED ADA payment-tx CBOR. It runs server-side, where the sidecar
 * (127.0.0.1:4000) is reachable — a browser cannot reach the sidecar directly,
 * which is why the old admin-proxy path returned HTML and broke the button.
 *
 * Body:  { buyer_addr, recipient_addr, amount_lovelace }
 * Reply: { ok:true, cbor_hex } | { ok:false, error }
 *
 * Builds only an UNSIGNED tx that the buyer signs in their own wallet — no funds
 * move server-side, no private keys involved.
 */
declare(strict_types=1);

require_once __DIR__ . '/../src/Config.php';
require_once __DIR__ . '/../src/Db.php';
require_once __DIR__ . '/../src/Api/Cors.php';
require_once __DIR__ . '/../src/Api/RateLimit.php';
require_once __DIR__ . '/../src/Sidecar/Client.php';

use RareFolio\Config;
use RareFolio\Db;
use RareFolio\Api\Cors;
use RareFolio\Api\RateLimit;
use RareFolio\Sidecar\Client as SidecarClient;

Config::load(__DIR__ . '/../.env');
Cors::apply();
RateLimit::enforce('payment-prepare');

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
if ($method === 'OPTIONS') { http_response_code(204); exit; }
if ($method !== 'POST') {
    http_response_code(405);
    header('Allow: POST, OPTIONS');
    echo json_encode(['ok' => false, 'error' => 'method not allowed']);
    exit;
}

header('Content-Type: application/json');

$raw     = file_get_contents('php://input');
$decoded = json_decode((string)$raw, true);
$body    = is_array($decoded) ? $decoded : [];

$buyerAddr      = trim((string)($body['buyer_addr'] ?? ''));
$recipientAddr  = trim((string)($body['recipient_addr'] ?? ''));
$amountLovelace = (int)($body['amount_lovelace'] ?? 0);

if ($buyerAddr === '' || $recipientAddr === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'buyer_addr and recipient_addr are required']);
    exit;
}
if ($amountLovelace < 1_000_000) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'amount_lovelace must be at least 1000000 (1 ADA)']);
    exit;
}

// Guard: recipient must be a known collection split wallet — not an open tx-builder relay.
try {
    $pdo = Db::pdo();
    $chk = $pdo->prepare('SELECT 1 FROM qd_collections WHERE split_wallet_addr = ? LIMIT 1');
    $chk->execute([$recipientAddr]);
    if (!$chk->fetchColumn()) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'recipient is not a recognized sale wallet']);
        exit;
    }
} catch (Throwable $e) {
    error_log('[payment-prepare] split-wallet check failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'server error validating recipient']);
    exit;
}

// Build the unsigned tx via the sidecar (server-side; 127.0.0.1:4000 reachable here).
try {
    $sidecar  = new SidecarClient();
    $prepared = $sidecar->preparePayment([
        'buyer_addr'      => $buyerAddr,
        'recipient_addr'  => $recipientAddr,
        'amount_lovelace' => $amountLovelace,
    ]);
    $cborHex = trim((string)($prepared['cbor_hex'] ?? ''));
    if ($cborHex === '' || !preg_match('/^[0-9a-fA-F]+$/', $cborHex)) {
        throw new RuntimeException('sidecar returned no cbor_hex');
    }
    echo json_encode(['ok' => true, 'cbor_hex' => $cborHex]);
} catch (Throwable $e) {
    error_log('[payment-prepare] sidecar error: ' . $e->getMessage());
    $msg = $e->getMessage();
    if (stripos($msg, 'no_utxos') !== false || stripos($msg, 'No UTxOs') !== false) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'No spendable ADA found in the connected wallet on mainnet.']);
        exit;
    }
    http_response_code(502);
    echo json_encode(['ok' => false, 'error' => 'could not build payment transaction, please try the manual option']);
}
