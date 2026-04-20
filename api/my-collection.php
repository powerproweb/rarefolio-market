<?php
/**
 * My Collection API endpoint.
 *
 * POST /api/my-collection.php
 *
 * Takes one or more wallet addresses (from CIP-30 getUsedAddresses()) and
 * returns all RareFolio tokens owned by those addresses.
 *
 * Body (JSON):
 *   { "addresses": ["addr1...", "addr_hex..."] }
 *
 * Response:
 *   { "ok": true, "tokens": [...], "orders": [...] }
 *
 * Matching strategy:
 *   1. qd_tokens.current_owner_wallet IN (addresses)       — post-mint ownership
 *   2. qd_orders WHERE buyer_addr IN (addresses) AND status='settled' — settled orders
 *      (covers the window between settlement and next ownership sync)
 */
declare(strict_types=1);

require_once __DIR__ . '/../src/Config.php';
require_once __DIR__ . '/../src/Db.php';
require_once __DIR__ . '/../src/Api/Cors.php';
require_once __DIR__ . '/../src/Api/RateLimit.php';

use RareFolio\Config;
use RareFolio\Db;
use RareFolio\Api\Cors;
use RareFolio\Api\RateLimit;

Config::load(__DIR__ . '/../.env');
Cors::apply();
RateLimit::enforce('my-collection');

header('Content-Type: application/json');

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
if ($method === 'OPTIONS') { http_response_code(204); exit; }
if ($method !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'POST required']);
    exit;
}

$body      = json_decode(file_get_contents('php://input') ?: '{}', true) ?: [];
$rawAddrs  = $body['addresses'] ?? [];

if (!is_array($rawAddrs) || empty($rawAddrs)) {
    echo json_encode(['ok' => true, 'tokens' => [], 'orders' => []]);
    exit;
}

// Sanitise and limit addresses (max 20 — a wallet can have many addresses)
$addresses = array_slice(
    array_filter(array_map('trim', $rawAddrs), fn($a) => strlen($a) >= 10),
    0, 20
);

if (empty($addresses)) {
    echo json_encode(['ok' => true, 'tokens' => [], 'orders' => []]);
    exit;
}

try {
    $pdo = Db::pdo();

    $in = implode(',', array_fill(0, count($addresses), '?'));

    // 1. Owned tokens (current_owner_wallet)
    $tStmt = $pdo->prepare("
        SELECT t.rarefolio_token_id, t.title, t.character_name, t.edition,
               t.collection_slug, t.cip25_json, t.mint_tx_hash, t.minted_at,
               t.primary_sale_status, t.asset_fingerprint,
               t.policy_id, t.asset_name_hex, t.current_owner_wallet,
               c.name AS collection_name
          FROM qd_tokens t
          LEFT JOIN qd_collections c ON c.slug = t.collection_slug
         WHERE t.current_owner_wallet IN ($in)
           AND t.primary_sale_status IN ('minted','sold')
         ORDER BY t.minted_at DESC
    ");
    $tStmt->execute($addresses);
    $rawTokens = $tStmt->fetchAll(PDO::FETCH_ASSOC);

    // 2. Settled orders where NFT may not yet be synced to qd_tokens
    $oStmt = $pdo->prepare("
        SELECT o.id AS order_id, o.rarefolio_token_id, o.buyer_addr,
               o.sale_amount_lovelace, o.order_tx_hash, o.settled_at,
               t.title, t.character_name, t.edition, t.cip25_json,
               t.collection_slug, c.name AS collection_name
          FROM qd_orders o
          LEFT JOIN qd_tokens t ON t.rarefolio_token_id = o.rarefolio_token_id
          LEFT JOIN qd_collections c ON c.slug = t.collection_slug
         WHERE o.buyer_addr IN ($in)
           AND o.status = 'settled'
         ORDER BY o.settled_at DESC
    ");
    $oStmt->execute($addresses);
    $rawOrders = $oStmt->fetchAll(PDO::FETCH_ASSOC);

    // Format token data
    function extractImg(mixed $v): string {
        if (is_array($v)) $v = implode('', $v);
        return is_string($v) ? $v : '';
    }

    function formatToken(array $row): array {
        $cip25 = json_decode($row['cip25_json'] ?? '{}', true) ?: [];
        $rawImg = $cip25['image'] ?? '';
        $img = extractImg($rawImg);
        if (str_starts_with($img, 'ipfs://')) {
            $img = 'https://gateway.pinata.cloud/ipfs/' . substr($img, 7);
        }
        $desc = $cip25['description'] ?? '';
        if (is_array($desc)) $desc = implode(' ', $desc);
        return [
            'cnft_id'         => $row['rarefolio_token_id'],
            'title'           => $row['title'] ?? '',
            'character_name'  => $row['character_name'] ?? '',
            'edition'         => $row['edition'] ?? '',
            'collection'      => $row['collection_name'] ?? $row['collection_slug'] ?? '',
            'collection_slug' => $row['collection_slug'] ?? '',
            'image_url'       => $img,
            'description'     => mb_substr($desc, 0, 160),
            'mint_tx_hash'    => $row['mint_tx_hash'] ?? null,
            'minted_at'       => $row['minted_at'] ?? null,
            'fingerprint'     => $row['asset_fingerprint'] ?? null,
            'status'          => $row['primary_sale_status'] ?? 'minted',
        ];
    }

    $tokens = array_map('formatToken', $rawTokens);

    // IDs already found via token ownership (don't duplicate in orders list)
    $foundIds = array_column($tokens, 'cnft_id');

    $orders = [];
    foreach ($rawOrders as $o) {
        if (in_array($o['rarefolio_token_id'], $foundIds, true)) continue;
        $cip25 = json_decode($o['cip25_json'] ?? '{}', true) ?: [];
        $rawImg = $cip25['image'] ?? '';
        $img = extractImg($rawImg);
        if (str_starts_with($img, 'ipfs://')) {
            $img = 'https://gateway.pinata.cloud/ipfs/' . substr($img, 7);
        }
        $orders[] = [
            'order_id'       => (int)$o['order_id'],
            'cnft_id'        => $o['rarefolio_token_id'],
            'title'          => $o['title'] ?? '',
            'character_name' => $o['character_name'] ?? '',
            'edition'        => $o['edition'] ?? '',
            'collection'     => $o['collection_name'] ?? $o['collection_slug'] ?? '',
            'image_url'      => $img,
            'amount_ada'     => round($o['sale_amount_lovelace'] / 1_000_000, 2),
            'settled_at'     => $o['settled_at'],
            'note'           => 'Ownership sync pending — NFT on its way to your wallet.',
        ];
    }

    echo json_encode([
        'ok'     => true,
        'tokens' => $tokens,
        'orders' => $orders,
        'total'  => count($tokens) + count($orders),
    ]);

} catch (Throwable $e) {
    error_log('[my-collection] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'database error']);
}
