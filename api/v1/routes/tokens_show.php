<?php
declare(strict_types=1);

use RareFolio\Api\Response;
use RareFolio\Api\Validator;
use RareFolio\Config;
use RareFolio\Db;

/**
 * GET /api/v1/tokens/{id}
 *
 * Lookup a single CNFT by its rarefolio_token_id (e.g. "qd-silver-0000001").
 * Returns enough information to power verify.html / nft.html on the public site
 * without exposing internal-only columns.
 *
 * @var array{id:string} $params supplied by the router
 */

try {
    $cnftId = Validator::cnftId((string) ($params['id'] ?? ''));
} catch (InvalidArgumentException $e) {
    Response::badRequest($e->getMessage());
    exit;
}

if (!Config::get('DB_NAME') || !Config::get('DB_USER')) {
    Response::error(503, 'database not configured');
    exit;
}

try {
    $pdo = Db::pdo();
    $stmt = $pdo->prepare('
        SELECT
            t.rarefolio_token_id,
            t.policy_id,
            t.asset_name_hex,
            t.asset_name_utf8,
            t.asset_fingerprint,
            t.collection_slug,
            c.network AS collection_network,
            t.title,
            t.character_name,
            t.edition,
            t.artist,
            t.mint_tx_hash,
            t.minted_at,
            t.current_owner_wallet,
            t.custody_status,
            t.listing_status,
            t.primary_sale_status,
            t.secondary_eligible,
            t.cip25_json,
            t.updated_at
        FROM qd_tokens t
        LEFT JOIN qd_collections c
            ON c.slug = t.collection_slug
        WHERE t.rarefolio_token_id = :id
        LIMIT 1
    ');
    $stmt->execute([':id' => $cnftId]);
    $row = $stmt->fetch();
} catch (Throwable $e) {
    error_log('[api v1 tokens_show] ' . $e->getMessage());
    Response::error(500, 'database error');
    exit;
}

if (!$row) {
    Response::notFound('token not found: ' . $cnftId);
    exit;
}

// Try to pull bar_serial from CIP-25 attributes; fall back to null if unknown.
$barSerial = null;
$cip25 = null;
if (!empty($row['cip25_json'])) {
    $cip25 = json_decode((string) $row['cip25_json'], true) ?: null;
    if (is_array($cip25)) {
        $candidates = [
            $cip25['bar_serial']              ?? null,
            $cip25['attributes']['bar_serial']?? null,
            $cip25['properties']['bar_serial']?? null,
        ];
        foreach ($candidates as $c) {
            if (is_string($c) && $c !== '') { $barSerial = $c; break; }
        }
    }
}

$network = (string) ($row['collection_network'] ?? Config::get('BLOCKFROST_NETWORK', 'preprod'));

// Redact wallet to first/last 6 chars — full ownership is not a public field.
$ownerDisplay = null;
$w = $row['current_owner_wallet'];
if (is_string($w) && strlen($w) > 14) {
    $ownerDisplay = substr($w, 0, 8) . '…' . substr($w, -6);
} elseif (is_string($w) && $w !== '') {
    $ownerDisplay = $w;
}

Response::ok([
    'cnft_id'          => $row['rarefolio_token_id'],
    'title'            => $row['title'],
    'character_name'   => $row['character_name'],
    'edition'          => $row['edition'],
    'artist'           => $row['artist'],
    'collection'       => $row['collection_slug'],
    'bar_serial'       => $barSerial,
    'chain'            => [
        'network'            => $network,
        'policy_id'          => $row['policy_id'],
        'asset_name_hex'     => $row['asset_name_hex'],
        'asset_name_utf8'    => $row['asset_name_utf8'],
        'asset_fingerprint'  => $row['asset_fingerprint'],
        'mint_tx_hash'       => $row['mint_tx_hash'],
        'minted_at'          => $row['minted_at'],
    ],
    'status'           => [
        'primary_sale'      => $row['primary_sale_status'],
        'listing'           => $row['listing_status'],
        'custody'           => $row['custody_status'],
        'secondary_eligible'=> (bool) ((int) $row['secondary_eligible']),
    ],
    'owner_display'    => $ownerDisplay,
    'updated_at'       => $row['updated_at'],
]);
