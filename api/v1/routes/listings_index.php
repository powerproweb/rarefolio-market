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
 *             Also adds lazy-mint synthetic rows for unminted tokens that are
 *             activated for sale in qd_tokens and have a collection sale price.
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
    $hasCollectionsTable = (bool) $pdo
        ->query("SELECT COUNT(*) FROM information_schema.tables
                  WHERE table_schema = DATABASE() AND table_name = 'qd_collections'")
        ->fetchColumn();

    if ($hasListingsTable) {
        // ----------------------------------------------------------------
        // Phase 2 path: qd_listings JOIN qd_tokens (+ lazy-mint union)
        // ----------------------------------------------------------------
        $binds = [];
        $whereRealParts = ["l.status = 'active'"];

        if ($format !== null) {
            $whereRealParts[] = 'l.sale_format = :format';
            $binds[':format'] = $format;
        }

        if ($bar !== null) {
            $whereRealParts[] = "
                (
                    JSON_UNQUOTE(JSON_EXTRACT(t.cip25_json, '$.bar_serial'))               = :bar_r_1
                    OR JSON_UNQUOTE(JSON_EXTRACT(t.cip25_json, '$.attributes.bar_serial')) = :bar_r_2
                    OR t.collection_slug LIKE :bar_r_like
                )
            ";
            $binds[':bar_r_1']    = $bar;
            $binds[':bar_r_2']    = $bar;
            $binds[':bar_r_like'] = '%' . $bar . '%';
        }

        $collectionJoin = $hasCollectionsTable
            ? 'LEFT JOIN qd_collections c ON c.slug = t.collection_slug'
            : '';
        $realPriceExpr = $hasCollectionsTable
            ? 'COALESCE(l.asking_price_lovelace, c.primary_sale_price_lovelace)'
            : 'l.asking_price_lovelace';
        $realMintModeExpr = $hasCollectionsTable
            ? "CASE
                WHEN t.primary_sale_status = 'unminted'
                     AND t.listing_status IN ('listed_fixed','listed_auction','offer_only')
                     AND COALESCE(c.primary_sale_price_lovelace, 0) > 0
                THEN 'on_demand'
                ELSE 'pre_minted'
               END"
            : "CASE
                WHEN t.primary_sale_status = 'unminted' THEN 'on_demand'
                ELSE 'pre_minted'
               END";

        $whereReal = implode(' AND ', $whereRealParts);
        $realSelect = "
            SELECT
                l.id              AS listing_id,
                t.rarefolio_token_id, t.collection_slug, t.title, t.character_name,
                t.edition, t.asset_fingerprint,
                l.sale_format,
                {$realPriceExpr} AS asking_price_lovelace,
                l.currency,
                l.starts_at,
                l.ends_at,
                l.updated_at,
                t.primary_sale_status,
                t.listing_status,
                {$realMintModeExpr} AS mint_mode
            FROM qd_listings l
            JOIN qd_tokens t ON t.id = l.nft_id
            {$collectionJoin}
            WHERE {$whereReal}
        ";

        $unionParts = [$realSelect];

        if ($hasCollectionsTable) {
            $whereLazyParts = [
                "t.primary_sale_status = 'unminted'",
                "t.listing_status = 'listed_fixed'",
                'COALESCE(c.primary_sale_price_lovelace, 0) > 0',
                "NOT EXISTS (
                    SELECT 1
                    FROM qd_listings l2
                    WHERE l2.nft_id = t.id AND l2.status = 'active'
                )",
            ];

            if ($format !== null && $format !== 'fixed') {
                $whereLazyParts[] = '1 = 0';
            }

            if ($bar !== null) {
                $whereLazyParts[] = "
                    (
                        JSON_UNQUOTE(JSON_EXTRACT(t.cip25_json, '$.bar_serial'))               = :bar_l_1
                        OR JSON_UNQUOTE(JSON_EXTRACT(t.cip25_json, '$.attributes.bar_serial')) = :bar_l_2
                        OR t.collection_slug LIKE :bar_l_like
                    )
                ";
                $binds[':bar_l_1']    = $bar;
                $binds[':bar_l_2']    = $bar;
                $binds[':bar_l_like'] = '%' . $bar . '%';
            }

            $whereLazy = implode(' AND ', $whereLazyParts);
            $lazySelect = "
                SELECT
                    NULL AS listing_id,
                    t.rarefolio_token_id, t.collection_slug, t.title, t.character_name,
                    t.edition, t.asset_fingerprint,
                    'fixed' AS sale_format,
                    c.primary_sale_price_lovelace AS asking_price_lovelace,
                    'ADA' AS currency,
                    NULL AS starts_at,
                    NULL AS ends_at,
                    t.updated_at,
                    t.primary_sale_status,
                    t.listing_status,
                    'on_demand' AS mint_mode
                FROM qd_tokens t
                LEFT JOIN qd_collections c ON c.slug = t.collection_slug
                WHERE {$whereLazy}
            ";
            $unionParts[] = $lazySelect;
        }

        $unionSql = implode("\nUNION ALL\n", $unionParts);
        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM ({$unionSql}) listing_union");
        $countStmt->execute($binds);
        $total = (int) $countStmt->fetchColumn();

        $listStmt = $pdo->prepare("
            SELECT
                listing_id,
                rarefolio_token_id, collection_slug, title, character_name,
                edition, asset_fingerprint,
                sale_format,
                asking_price_lovelace,
                currency,
                starts_at,
                ends_at,
                updated_at,
                primary_sale_status,
                listing_status,
                mint_mode
            FROM ({$unionSql}) listing_union
            ORDER BY updated_at DESC
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
                'listing_id'        => $r['listing_id'] !== null ? (int) $r['listing_id'] : null,
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
                'status'            => [
                    'primary_sale' => $r['primary_sale_status'],
                    'listing'      => $r['listing_status'],
                    'mint_mode'    => $r['mint_mode'],
                ],
            ], $rows),
        ]);
    } else {
        // ----------------------------------------------------------------
        // Phase 1 fallback: qd_tokens.listing_status
        // ----------------------------------------------------------------
        $from = $hasCollectionsTable
            ? 'qd_tokens t LEFT JOIN qd_collections c ON c.slug = t.collection_slug'
            : 'qd_tokens t';
        $whereParts = ["t.listing_status IN ('listed_fixed','listed_auction','offer_only')"];
        $binds = [];

        if ($hasCollectionsTable) {
            $whereParts[] = "(t.primary_sale_status <> 'unminted' OR COALESCE(c.primary_sale_price_lovelace, 0) > 0)";
        }

        if ($format !== null) {
            if ($format === 'fixed') {
                $whereParts[] = "t.listing_status = 'listed_fixed'";
            } elseif ($format === 'auction') {
                $whereParts[] = "t.listing_status = 'listed_auction'";
            } elseif ($format === 'offer_only') {
                $whereParts[] = "t.listing_status = 'offer_only'";
            } else {
                $whereParts[] = '1 = 0';
            }
        }

        if ($bar !== null) {
            $whereParts[] = "
                (
                    JSON_UNQUOTE(JSON_EXTRACT(t.cip25_json, '$.bar_serial'))               = :bar_f_1
                    OR JSON_UNQUOTE(JSON_EXTRACT(t.cip25_json, '$.attributes.bar_serial')) = :bar_f_2
                    OR t.collection_slug LIKE :bar_f_like
                )
            ";
            $binds[':bar_f_1']    = $bar;
            $binds[':bar_f_2']    = $bar;
            $binds[':bar_f_like'] = '%' . $bar . '%';
        }

        $where = implode(' AND ', $whereParts);
        $mintModeExpr = $hasCollectionsTable
            ? "CASE
                WHEN t.primary_sale_status = 'unminted' AND COALESCE(c.primary_sale_price_lovelace, 0) > 0
                THEN 'on_demand'
                ELSE 'pre_minted'
               END"
            : "CASE
                WHEN t.primary_sale_status = 'unminted' THEN 'on_demand'
                ELSE 'pre_minted'
               END";

        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM {$from} WHERE {$where}");
        $countStmt->execute($binds);
        $total = (int) $countStmt->fetchColumn();

        $listStmt = $pdo->prepare("
            SELECT
                t.rarefolio_token_id, t.collection_slug, t.title, t.character_name, t.edition,
                t.listing_status, t.asset_fingerprint, t.updated_at, t.primary_sale_status,
                {$mintModeExpr} AS mint_mode
            FROM {$from}
            WHERE {$where}
            ORDER BY t.updated_at DESC
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
                'sale_format'       => $r['listing_status'] === 'listed_fixed'
                    ? 'fixed'
                    : ($r['listing_status'] === 'listed_auction' ? 'auction' : 'offer_only'),
                'price_lovelace'    => null,
                'price_ada'         => null,
                'updated_at'        => $r['updated_at'],
                'status'            => [
                    'primary_sale' => $r['primary_sale_status'],
                    'listing'      => $r['listing_status'],
                    'mint_mode'    => $r['mint_mode'],
                ],
            ], $rows),
        ]);
    }
} catch (Throwable $e) {
    error_log('[api v1 listings_index] ' . $e->getMessage());
    Response::error(500, 'database error');
    exit;
}
