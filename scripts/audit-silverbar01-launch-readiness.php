<?php
/**
 * READ-ONLY launch-readiness audit for Silver Bar I.
 *
 * For every token the DB believes is minted/sold, compare DB state against the
 * actual blockchain via Blockfrost, and flag every drift. NOTHING is written or sent.
 *
 * Catches exactly the class of bug that bit us before: "market says not minted but the
 * NFT is really in a wallet", owner mismatches, and missing/duplicate companions.
 *
 * RUN:   php scripts/audit-silverbar01-launch-readiness.php
 *        php scripts/audit-silverbar01-launch-readiness.php --slug-like='silverbar-01%'
 *
 * Output: one line per token (OK / DRIFT / WARN) + a summary. Exit code 0 if no DRIFT,
 * 1 if any hard DRIFT found (safe to wire into a pre-launch gate).
 *
 * Caveats (read before acting on WARN lines):
 *  - Owner + companion checks look at the single ADDRESS that holds the NFT. A multi-address
 *    wallet can legitimately hold the companion at a different address, which would show as a
 *    WARN, not a true error. Treat WARN as "review", DRIFT as "must fix".
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../src/Config.php';
require_once __DIR__ . '/../src/Db.php';
require_once __DIR__ . '/../src/Blockfrost/Client.php';

use RareFolio\Config;
use RareFolio\Db;
use RareFolio\Blockfrost\Client as BlockfrostClient;

Config::load(__DIR__ . '/../.env');

// ---- scope -------------------------------------------------------------
$slugLike = 'silverbar-01%';
foreach ($argv as $a) {
    if (preg_match('/^--slug-like=(.+)$/', $a, $m)) $slugLike = $m[1];
}
const DB_MINTED_STATUSES = ['minted', 'sold', 'sold_pre_marketplace'];

// ---- Blockfrost direct address lookup (companion balance at an address) -
$network = (string) Config::get('BLOCKFROST_NETWORK', 'preprod');
$apiKey  = (string) Config::required('BLOCKFROST_API_KEY');
$bfBase  = match ($network) {
    'mainnet' => 'https://cardano-mainnet.blockfrost.io/api/v0',
    'preprod' => 'https://cardano-preprod.blockfrost.io/api/v0',
    'preview' => 'https://cardano-preview.blockfrost.io/api/v0',
    default   => throw new RuntimeException("Unknown network: $network"),
};
$addrCache = [];
/** @return array<string,int> unit => quantity for an address (empty if none/404) */
function bf_address_amounts(string $addr, string $bfBase, string $apiKey, array &$cache): array
{
    if (isset($cache[$addr])) return $cache[$addr];
    $ch = curl_init("$bfBase/addresses/" . rawurlencode($addr));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_HTTPHEADER => ['project_id: ' . $apiKey, 'Accept: application/json'],
    ]);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $out = [];
    if ($code === 200) {
        $j = json_decode((string) $body, true);
        foreach (($j['amount'] ?? []) as $a) {
            if (isset($a['unit'])) $out[(string) $a['unit']] = (int) ($a['quantity'] ?? 0);
        }
    }
    $cache[$addr] = $out;
    return $out;
}

/** Resolve the companion unit from cip25 (mirror of resolve_companion_unit). */
function audit_resolve_companion_unit(array $cip25): ?string
{
    $candidates = [
        $cip25['companion_unit'] ?? null,
        $cip25['silver_shard_unit'] ?? null,
        $cip25['companion']['unit'] ?? null,
        $cip25['attributes']['companion_unit'] ?? null,
        $cip25['attributes']['silver_shard_unit'] ?? null,
    ];
    foreach ($candidates as $c) {
        if (is_string($c) && trim($c) !== '') {
            $u = strtolower(preg_replace('/^0x/i', '', trim($c)));
            if (preg_match('/^[0-9a-f]{56,}$/', $u)) return $u;
        }
    }
    return null;
}

// ---- run ---------------------------------------------------------------
$pdo = Db::pdo();
$bf  = new BlockfrostClient();

$in = implode(',', array_fill(0, count(DB_MINTED_STATUSES), '?'));
$sql = "SELECT rarefolio_token_id, collection_slug, policy_id, asset_name_hex,
               current_owner_wallet, custody_status, primary_sale_status, cip25_json
          FROM qd_tokens
         WHERE primary_sale_status IN ($in)
           AND collection_slug LIKE ?
           AND rarefolio_token_id NOT LIKE 'qd-e2e-%'   -- exclude leftover E2E test tokens
           AND rarefolio_token_id NOT LIKE 'qd-test-%'
         ORDER BY rarefolio_token_id";
$stmt = $pdo->prepare($sql);
$stmt->execute([...DB_MINTED_STATUSES, $slugLike]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

fwrite(STDERR, "Audit scope: collection_slug LIKE '$slugLike', status IN (" . implode(',', DB_MINTED_STATUSES) . "), network=$network — " . count($rows) . " token(s)\n\n");

$drift = 0; $warn = 0; $ok = 0;
foreach ($rows as $r) {
    $tokenId = (string) $r['rarefolio_token_id'];
    $dbOwner = trim((string) ($r['current_owner_wallet'] ?? ''));
    $policyId = strtolower(trim((string) ($r['policy_id'] ?? '')));
    $assetHex = strtolower(trim((string) ($r['asset_name_hex'] ?? '')));
    $cip25 = json_decode((string) ($r['cip25_json'] ?? ''), true);
    $cip25 = is_array($cip25) ? $cip25 : [];

    if ($policyId === '' || $assetHex === '' || !preg_match('/^[0-9a-f]{56}$/', $policyId)) {
        $drift++; echo "DRIFT  $tokenId  db_minted_but_no_onchain_unit (policy_id/asset_name_hex missing)\n"; continue;
    }
    $unit = $policyId . $assetHex;

    usleep(130000); // be gentle with Blockfrost rate limits
    $asset = null; $chainOwner = null;
    try {
        $asset = $bf->asset($unit);
        if ($asset === null) {
            $drift++; echo "DRIFT  $tokenId  DB says '{$r['primary_sale_status']}' but asset NOT on chain (unit=$unit)\n"; continue;
        }
        $chainOwner = $bf->currentOwner($unit);
    } catch (Throwable $e) {
        $warn++; echo "WARN   $tokenId  blockfrost_error: " . $e->getMessage() . "\n"; continue;
    }

    if ($chainOwner === null) {
        $drift++; echo "DRIFT  $tokenId  minted on chain but NO current holder (burned/zero qty?)\n"; continue;
    }

    $issues = [];
    if ($dbOwner !== '' && $dbOwner !== $chainOwner) {
        $issues[] = "owner_mismatch db=$dbOwner chain=$chainOwner";
    }

    $companionUnit = audit_resolve_companion_unit($cip25);
    if ($companionUnit !== null) {
        $amounts = bf_address_amounts($chainOwner, $bfBase, $apiKey, $addrCache);
        $cq = (int) ($amounts[$companionUnit] ?? 0);
        if ($cq === 0)      $issues[] = "companion_missing_at_holder_address";
        elseif ($cq > 1)    $issues[] = "companion_qty_at_holder=$cq (expected 1; may be a custody wallet)";
    } else {
        $issues[] = "companion_unit_unresolved_in_cip25";
    }

    if ($issues === []) { $ok++; echo "OK     $tokenId  owner=$chainOwner companion=1\n"; }
    else { $warn++; echo "WARN   $tokenId  " . implode(' | ', $issues) . "\n"; }
}

echo "\nSummary: OK=$ok  WARN=$warn  DRIFT=$drift  of " . count($rows) . " token(s).\n";
echo ($drift > 0 ? "RESULT: NOT launch-ready — resolve DRIFT lines.\n" : "RESULT: no hard DRIFT. Review WARN lines (custody wallets are expected to hold many companions).\n");
exit($drift > 0 ? 1 : 0);
