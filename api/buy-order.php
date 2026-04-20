<?php
/**
 * Public buy-order endpoint.
 *
 * POST /api/buy-order.php
 *
 * Called by buy.php after a buyer has sent (or is confirming) payment.
 * Creates a row in qd_orders with status=submitted.
 *
 * Body (JSON):
 *   token_id        string   rarefolio_token_id
 *   buyer_addr      string   buyer's wallet address (bech32 or hex-CBOR)
 *   tx_hash         string   payment transaction hash
 *   amount_lovelace number   amount sent
 *
 * Response:
 *   { ok: true, order_id: N }   on success
 *   { ok: false, error: "..." } on failure
 */
declare(strict_types=1);

require_once __DIR__ . '/../src/Config.php';
require_once __DIR__ . '/../src/Db.php';
require_once __DIR__ . '/../src/Api/Cors.php';
require_once __DIR__ . '/../src/Api/RateLimit.php';
require_once __DIR__ . '/../src/Api/Response.php';

use RareFolio\Config;
use RareFolio\Db;
use RareFolio\Api\Cors;
use RareFolio\Api\RateLimit;
use RareFolio\Api\Response;

Config::load(__DIR__ . '/../.env');
Cors::apply();
RateLimit::enforce('buy-order');

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
if ($method === 'OPTIONS') { http_response_code(204); exit; }
if ($method !== 'POST') {
    http_response_code(405);
    header('Allow: POST, OPTIONS');
    echo json_encode(['ok' => false, 'error' => 'method not allowed']);
    exit;
}

header('Content-Type: application/json');

$raw  = file_get_contents('php://input');
$body = json_decode((string)$raw, true) ?: [];

$tokenId        = trim((string)($body['token_id']        ?? ''));
$buyerAddr      = trim((string)($body['buyer_addr']      ?? ''));
$txHash         = trim((string)($body['tx_hash']         ?? ''));
$amountLovelace = (int)($body['amount_lovelace']         ?? 0);

// Validate
if ($tokenId === '' || $buyerAddr === '' || $txHash === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'token_id, buyer_addr, and tx_hash are required']);
    exit;
}
if (!preg_match('/^[0-9a-f]{64}$/i', $txHash)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'tx_hash must be a 64-character hex string']);
    exit;
}

try {
    $pdo = Db::pdo();

    // Look up the token
    $tStmt = $pdo->prepare(
        "SELECT t.id AS nft_id, t.rarefolio_token_id, t.primary_sale_status,
                c.split_wallet_addr, c.royalty_total_pct, c.platform_fee_pct,
                c.primary_sale_price_lovelace AS collection_price
           FROM qd_tokens t
           LEFT JOIN qd_collections c ON c.slug = t.collection_slug
          WHERE t.rarefolio_token_id = ?
          LIMIT 1"
    );
    $tStmt->execute([$tokenId]);
    $token = $tStmt->fetch();

    if (!$token) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'token not found']);
        exit;
    }

    // Check not already sold
    if (in_array($token['primary_sale_status'], ['sold', 'sold_pre_marketplace'], true)) {
        http_response_code(409);
        echo json_encode(['ok' => false, 'error' => 'this token has already been sold']);
        exit;
    }

    // Check for duplicate tx_hash (prevent double orders)
    $dupStmt = $pdo->prepare('SELECT id FROM qd_orders WHERE order_tx_hash = ? LIMIT 1');
    $dupStmt->execute([$txHash]);
    if ($dupStmt->fetch()) {
        http_response_code(409);
        echo json_encode(['ok' => false, 'error' => 'this transaction hash has already been recorded']);
        exit;
    }

    // Resolve price (use amount sent; could cross-validate with collection price)
    $priceLovelace = $amountLovelace > 0
        ? $amountLovelace
        : (int)($token['collection_price'] ?? 0);

    // Calculate fee/royalty splits (based on collection config)
    $royaltyPct  = (float)($token['royalty_total_pct']  ?? 8.0);
    $platformPct = (float)($token['platform_fee_pct']   ?? 2.5);
    $royaltyLovelace  = (int)round($priceLovelace * $royaltyPct  / 100);
    $platformLovelace = (int)round($priceLovelace * $platformPct / 100);
    $sellerNet        = $priceLovelace - $royaltyLovelace - $platformLovelace;

    $splitAddr    = (string)($token['split_wallet_addr'] ?? '');
    $platformAddr = $splitAddr; // for primary sales, all goes to split wallet

    // Create order
    $pdo->prepare(
        "INSERT INTO qd_orders
            (listing_id, nft_id, rarefolio_token_id,
             buyer_addr, seller_addr,
             sale_amount_lovelace, platform_fee_lovelace,
             creator_royalty_lovelace, seller_net_lovelace,
             creator_addr, platform_addr,
             order_tx_hash, status)
         VALUES
            (NULL, :nft_id, :tid,
             :buyer, :seller,
             :sale, :platform_fee,
             :royalty, :net,
             :creator, :platform,
             :tx, 'submitted')"
    )->execute([
        ':nft_id'       => $token['nft_id'],
        ':tid'          => $tokenId,
        ':buyer'        => $buyerAddr,
        ':seller'       => $splitAddr,
        ':sale'         => $priceLovelace,
        ':platform_fee' => $platformLovelace,
        ':royalty'      => $royaltyLovelace,
        ':net'          => $sellerNet,
        ':creator'      => $splitAddr,   // split wallet distributes to recipients
        ':platform'     => $platformAddr,
        ':tx'           => $txHash,
    ]);

    $orderId = (int) $pdo->lastInsertId();

    // Log to error_log for admin visibility until email notifications are added
    error_log("[buy-order] Order #{$orderId} created: token={$tokenId} buyer={$buyerAddr} tx={$txHash} amount={$priceLovelace}");

    echo json_encode(['ok' => true, 'order_id' => $orderId]);

} catch (Throwable $e) {
    error_log('[buy-order] ERROR: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'server error creating order']);
}
