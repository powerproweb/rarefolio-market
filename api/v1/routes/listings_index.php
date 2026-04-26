<?php
declare(strict_types=1);

use RareFolio\Api\Response;
use RareFolio\Api\Validator;
use RareFolio\Config;
use RareFolio\Db;

/**
 * GET /api/v1/listings
 *
 * Returns NFTs currently listed for sale.
 *
 * Strategy (auto-detected at runtime):
 *   Phase 2+: queries qd_listings JOIN qd_tokens when qd_listings exists.
 *             Includes real price, sale_format, and timing data.
 *   Phase 1 fallback: queries qd_tokens.listing_status when qd_listings
 *             has not been migrated yet (backward compatible).
 *
 * Query params:
 *   bar     (optional) — filter to one silver bar serial (e.g. E101837)
 *   format  (optional) — fixed | auction | offer_only
 *   limit   (optional) — 1..100, default 20
 *   offset  (optional) — 0..10000, default 0
 */

$barRaw    = isset($_GET['bar'])    ? (string) $_GET['bar']    : '';
$formatRaw = isset($_GET['format']) ? (string) $_GET['format'] : '';
$bar       = null;
$format    = null;

if ($barRaw !== '') {
    try {
        $bar = Validator::barSerial($barRaw);
    } catch (InvalidArgumentException $e) {
        Response::badRequest($e->getMessage());
        exit;
    }
}
if (in_array($formatRaw, ['fixed', 'auction', 'offer_only'], true)) {
    $format = $formatRaw;
}
$limit  = Validator::boundedInt($_GET['limit']  ?? null, 1, 100, 20);
$offset = Validator::boundedInt($_GET['offset'] ?? null, 0, 10000, 0);

if (!Config::get('DB_NAME') || !Config::get('DB_USER')) {
    Response::error(503, 'database not configured');
    exit;
}

try {
    $pdo = Db::pdo();

    // Detect whether the Phase 2 qd_listings table exists.
    $hasListingsTable = (bool) $pdo
        ->query("SELECT COUNT(*) FROM information_schema.tables
                  WHERE table_schema = DATABASE() AND table_name = 'qd_listings'")
        ->fetchColumn();

    if ($hasListingsTable) {
        // ----------------------------------------------------------------
        // Phase 2 path: qd_listings JOIN qd_tokens
        // ----------------------------------------------------------------
        $where  = "l.status = 'active'";
        $binds  = [];

        if ($format !== null) {
            $where .= ' AND l.sale_format = :format';
            $binds[':format'] = $format;
        }

        if ($bar !== null) {
            $where .= "
                AND (
                    JSON_UNQUOTE(JSON_EXTRACT(t.cip25_json, '$.bar_serial'))          = :bar_1
                    OR JSON_UNQUOTE(JSON_EXTRACT(t.cip25_json, '$.attributes.bar_serial')) = :bar_2
                    OR t.collection_slug LIKE :bar_like
                )
            ";
            $binds[':bar_1']    = $bar;
            $binds[':bar_2']    = $bar;
            $binds[':bar_like'] = '%' . $bar . '%';
        }

        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM qd_listings l JOIN qd_tokens t ON t.id = l.nft_id WHERE $where");
        $countStmt->execute($binds);
        $total = (int) $countStmt->fetchColumn();

        $listStmt = $pdo->prepare("
            SELECT
                t.rarefolio_token_id, t.collection_slug, t.title, t.character_name,
                t.edition, t.asset_fingerprint,
                l.id              AS listing_id,
                l.sale_format,
                l.asking_price_lovelace,
                l.currency,
                l.starts_at,
                l.ends_at,
                l.updated_at
            FROM qd_listings l
            JOIN qd_tokens t ON t.id = l.nft_id
            WHERE $where
            ORDER BY l.updated_at DESC
            LIMIT $limit OFFSET $offset
        ");
        $listStmt->execute($binds);
        $rows = $listStmt->fetchAll();

        Response::ok([
            'source'   => 'qd_listings',
            'total'    => $total,
            'limit'    => $limit,
            'offset'   => $offset,
            'listings' => array_map(fn (array $r): array => [
                'listing_id'        => (int) $r['listing_id'],
                'cnft_id'           => $r['rarefolio_token_id'],
                'title'             => $r['title'],
                'character_name'    => $r['character_name'],
                'edition'           => $r['edition'],
                'collection'        => $r['collection_slug'],
                'asset_fingerprint' => $r['asset_fingerprint'],
                'sale_format'       => $r['sale_format'],
                'price_lovelace'    => $r['asking_price_lovelace'] !== null ? (int) $r['asking_price_lovelace'] : null,
                'price_ada'         => $r['asking_price_lovelace'] !== null ? round((int) $r['asking_price_lovelace'] / 1_000_000, 6) : null,
                'currency'          => $r['currency'],
                'starts_at'         => $r['starts_at'],
                'ends_at'           => $r['ends_at'],
                'updated_at'        => $r['updated_at'],
            ], $rows),
        ]);
    } else {
        // ----------------------------------------------------------------
        // Phase 1 fallback: qd_tokens.listing_status
        // ----------------------------------------------------------------
        $where = "listing_status IN ('listed_fixed','listed_auction')";
        $binds = [];

        if ($bar !== null) {
            $where .= "
                AND (
                    JSON_UNQUOTE(JSON_EXTRACT(cip25_json, '$.bar_serial'))               = :bar_1
                    OR JSON_UNQUOTE(JSON_EXTRACT(cip25_json, '$.attributes.bar_serial')) = :bar_2
                    OR collection_slug LIKE :bar_like
                )
            ";
            $binds[':bar_1']    = $bar;
            $binds[':bar_2']    = $bar;
            $binds[':bar_like'] = '%' . $bar . '%';
        }

        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM qd_tokens WHERE $where");
        $countStmt->execute($binds);
        $total = (int) $countStmt->fetchColumn();

        $listStmt = $pdo->prepare("
            SELECT rarefolio_token_id, collection_slug, title, character_name, edition,
                   listing_status, asset_fingerprint, updated_at
            FROM qd_tokens
            WHERE $where
            ORDER BY updated_at DESC
            LIMIT $limit OFFSET $offset
        ");
        $listStmt->execute($binds);
        $rows = $listStmt->fetchAll();

        Response::ok([
            'source'   => 'qd_tokens_fallback',
            'total'    => $total,
            'limit'    => $limit,
            'offset'   => $offset,
            'listings' => array_map(fn (array $r): array => [
                'cnft_id'           => $r['rarefolio_token_id'],
                'title'             => $r['title'],
                'character_name'    => $r['character_name'],
                'edition'           => $r['edition'],
                'collection'        => $r['collection_slug'],
                'asset_fingerprint' => $r['asset_fingerprint'],
                'sale_format'       => $r['listing_status'] === 'listed_fixed' ? 'fixed' : 'auction',
                'price_lovelace'    => null,
                'price_ada'         => null,
                'updated_at'        => $r['updated_at'],
            ], $rows),
        ]);
    }
} catch (Throwable $e) {
    error_log('[api v1 listings_index] ' . $e->getMessage());
    Response::error(500, 'database error');
    exit;
}
