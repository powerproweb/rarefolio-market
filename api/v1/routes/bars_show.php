<?php
declare(strict_types=1);

use RareFolio\Api\Response;
use RareFolio\Api\Validator;
use RareFolio\Config;
use RareFolio\Db;

/**
 * GET /api/v1/bars/{serial}
 *
 * Returns aggregate stats for a physical silver bar identified by its serial
 * (e.g. "E101837"). Looks up tokens whose CIP-25 metadata or collection slug
 * references that bar.
 *
 * @var array{serial:string} $params supplied by the router
 */

try {
    $serial = Validator::barSerial((string) ($params['serial'] ?? ''));
} catch (InvalidArgumentException $e) {
    Response::badRequest($e->getMessage());
    exit;
}

if (!Config::get('DB_NAME') || !Config::get('DB_USER')) {
    Response::error(503, 'database not configured');
    exit;
}

// Physical bar spec keyed by serial.  Add entries as new bar serials go live.
// (A future v2 can move this into a qd_physical_bars table.)

try {
    $pdo = Db::pdo();

    // Match tokens by CIP-25 JSON attribute OR by collection_slug containing the serial.
    // Note: PDO::ATTR_EMULATE_PREPARES=false requires each named parameter to be unique
    // within a single query — use :s1/:s2/:s3 instead of repeating :serial_exact.
    $slugLike = '%' . $serial . '%';
    $binds = [
        ':s1'        => $serial,
        ':s2'        => $serial,
        ':s3'        => $serial,
        ':slug_like' => $slugLike,
    ];
    $whereClause = "
        JSON_UNQUOTE(JSON_EXTRACT(cip25_json, '$.bar_serial'))               = :s1
        OR JSON_UNQUOTE(JSON_EXTRACT(cip25_json, '$.attributes.bar_serial')) = :s2
        OR JSON_UNQUOTE(JSON_EXTRACT(cip25_json, '$.properties.bar_serial')) = :s3
        OR collection_slug LIKE :slug_like
    ";

    // Aggregate stats
    $aggStmt = $pdo->prepare("
        SELECT
            COUNT(*)                                                             AS total_tokens,
            SUM(primary_sale_status IN ('minted','sold','sold_pre_marketplace')) AS minted_tokens,
            SUM(listing_status IN ('listed_fixed','listed_auction'))             AS listed_tokens,
            MIN(minted_at)                                                       AS first_mint_at,
            MAX(updated_at)                                                      AS last_updated_at,
            MIN(collection_slug)                                                 AS collection_slug
        FROM qd_tokens
        WHERE $whereClause
    ");
    $aggStmt->execute($binds);
    $agg = $aggStmt->fetch();

    // Per-token list
    $tokStmt = $pdo->prepare("
        SELECT
            rarefolio_token_id,
            title,
            character_name,
            edition,
            asset_name_hex,
            asset_fingerprint,
            primary_sale_status,
            listing_status,
            mint_tx_hash,
            minted_at
        FROM qd_tokens
        WHERE $whereClause
        ORDER BY rarefolio_token_id
    ");
    $tokStmt->execute($binds);
    $tokenRows = $tokStmt->fetchAll();

    // Collection metadata from qd_collections (if the table exists)
    $collectionSlug = $agg['collection_slug'] ?? null;
    $collectionMeta = null;
    $hasCollectionsTable = (bool) $pdo
        ->query("SELECT COUNT(*) FROM information_schema.tables
                  WHERE table_schema = DATABASE() AND table_name = 'qd_collections'")
        ->fetchColumn();
    if ($hasCollectionsTable && $collectionSlug !== null) {
        $cStmt = $pdo->prepare("
            SELECT name, description, network, policy_id,
                   edition_size, primary_minted_count, all_primary_minted,
                   primary_sale_price_lovelace,
                   royalty_total_pct, platform_fee_pct
            FROM qd_collections
            WHERE slug = ?
            LIMIT 1
        ");
        $cStmt->execute([$collectionSlug]);
        $cRow = $cStmt->fetch();
        if ($cRow) {
            $collectionMeta = [
                'slug'                       => $collectionSlug,
                'name'                       => $cRow['name'],
                'description'                => $cRow['description'],
                'network'                    => $cRow['network'],
                'policy_id'                  => $cRow['policy_id'],
                'edition_size'               => (int) $cRow['edition_size'],
                'primary_minted_count'       => (int) $cRow['primary_minted_count'],
                'all_primary_minted'         => (bool) $cRow['all_primary_minted'],
                'primary_sale_price_lovelace'=> $cRow['primary_sale_price_lovelace'] !== null
                                                    ? (int) $cRow['primary_sale_price_lovelace']
                                                    : null,
                'primary_sale_price_ada'     => $cRow['primary_sale_price_lovelace'] !== null
                                                    ? round((int) $cRow['primary_sale_price_lovelace'] / 1_000_000, 6)
                                                    : null,
                'royalty_pct'                => (float) $cRow['royalty_total_pct'],
                'platform_fee_pct'           => (float) $cRow['platform_fee_pct'],
            ];
        }
    }
} catch (Throwable $e) {
    error_log('[api v1 bars_show] ' . $e->getMessage());
    Response::error(500, 'database error');
    exit;
}

$total = (int) ($agg['total_tokens'] ?? 0);

if ($total === 0) {
    Response::notFound('bar not found: ' . $serial);
    exit;
}

$physical = match ($serial) {
    'E101837' => ['weight_oz' => 100, 'purity' => '.999', 'material' => 'silver'],
    default   => ['weight_oz' => null, 'purity' => null, 'material' => 'silver'],
};

$tokens = array_map(fn (array $t): array => [
    'cnft_id'           => $t['rarefolio_token_id'],
    'title'             => $t['title'],
    'character_name'    => $t['character_name'],
    'edition'           => $t['edition'],
    'asset_name_hex'    => $t['asset_name_hex'],
    'asset_fingerprint' => $t['asset_fingerprint'],
    'primary_sale'      => $t['primary_sale_status'],
    'listing'           => $t['listing_status'],
    'mint_tx_hash'      => $t['mint_tx_hash'],
    'minted_at'         => $t['minted_at'],
], $tokenRows);

$payload = [
    'bar_serial'      => $serial,
    'physical'        => $physical,
    'total_tokens'    => $total,
    'minted_tokens'   => (int) ($agg['minted_tokens'] ?? 0),
    'listed_tokens'   => (int) ($agg['listed_tokens'] ?? 0),
    'first_mint_at'   => $agg['first_mint_at'],
    'last_updated_at' => $agg['last_updated_at'],
    'tokens'          => $tokens,
];

if ($collectionMeta !== null) {
    $payload['collection'] = $collectionMeta;
}

Response::ok($payload);
