<?php
/**
 * Mint queue action handler.
 *
 * Accepts two content types:
 *   - application/x-www-form-urlencoded (from HTML forms)
 *        -> issues a 302 redirect back to mint-detail.php with a flash message
 *   - application/json (from JS / fetch)
 *        -> returns JSON
 *
 * Supported `action` values:
 *   prepare       — ask sidecar /mint/prepare; store response note on the row
 *   prepare_json  — same but returns the sidecar JSON directly (for JS flow)
 *   record_tx     — persist the tx_hash returned from the wallet
 *   confirm       — call Blockfrost tx lookup; if found, move row to `confirmed`
 *   fail          — move row to `failed` with a message
 *   delete        — delete a draft/failed row
 */
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

use RareFolio\Blockfrost\Client as BlockfrostClient;
use RareFolio\Sidecar\Client as SidecarClient;

$isJson = str_starts_with($_SERVER['CONTENT_TYPE'] ?? '', 'application/json');
$body   = [];
if ($isJson) {
    $raw = file_get_contents('php://input') ?: '';
    $decoded = json_decode($raw, true);
    if (is_array($decoded)) {
        $body = $decoded;
    }
} else {
    $body = $_POST;
}

$id     = (int) ($body['id']     ?? $_GET['id']     ?? 0);
$action = (string) ($body['action'] ?? $_GET['action'] ?? '');

if ($id <= 0 || $action === '') {
    respond(['error' => 'missing id/action'], 400, $isJson, $id);
}

$stmt = $pdo->prepare('SELECT * FROM qd_mint_queue WHERE id = ?');
$stmt->execute([$id]);
$row = $stmt->fetch();
if (!$row) {
    respond(['error' => 'not found'], 404, $isJson, $id);
}

try {
    switch ($action) {
        case 'prepare':
        case 'prepare_json':
            $result = callSidecarPrepare($row, $body['recipient_addr_hex'] ?? null);
            if ($action === 'prepare_json') {
                respond($result, 200, true, $id);
            }
            // Form flow: bump attempts, store short note, redirect
            $pdo->prepare('UPDATE qd_mint_queue SET attempts = attempts + 1, updated_at = NOW() WHERE id = ?')
                ->execute([$id]);
            respond(
                ['ok' => true, 'message' => 'Sidecar prepare invoked. See JSON panel on detail page after signing.'],
                200, $isJson, $id, 'Sidecar prepare call succeeded.'
            );
            break;

        case 'record_tx':
            $tx = (string) ($body['tx_hash'] ?? '');
            if (!preg_match('/^[0-9a-f]{64}$/i', $tx)) {
                respond(['error' => 'invalid tx_hash'], 400, $isJson, $id);
            }
            $pdo->prepare(
                "UPDATE qd_mint_queue
                    SET tx_hash = ?, status = 'submitted', submitted_at = NOW(), updated_at = NOW()
                    WHERE id = ?"
            )->execute([$tx, $id]);
            respond(['ok' => true, 'tx_hash' => $tx], 200, $isJson, $id, 'tx_hash recorded, row marked submitted.');
            break;

        case 'confirm':
            if (empty($row['tx_hash'])) {
                respond(['error' => 'no tx_hash to confirm'], 400, $isJson, $id);
            }
            $bf = new BlockfrostClient();
            $tx = $bf->tx($row['tx_hash']);
            if ($tx === null) {
                respond(
                    ['ok' => false, 'message' => 'tx not found yet — try again shortly'],
                    200, $isJson, $id, 'tx not yet visible on-chain, check back in a minute.', 'warn'
                );
            }
            $pdo->prepare(
                "UPDATE qd_mint_queue SET status = 'confirmed', confirmed_at = NOW(), updated_at = NOW()
                    WHERE id = ?"
            )->execute([$id]);
            // Create / update the qd_tokens row
            upsertQdToken($pdo, $row, $tx);
            // Append provenance record (best-effort: silently skip if table not yet migrated)
            recordMintActivity($pdo, $row, $tx);
            respond(['ok' => true, 'tx' => $tx], 200, $isJson, $id, 'Confirmed on-chain. qd_tokens row created.');
            break;

        case 'submit_json':
            $cbor = (string) ($body['cbor_hex'] ?? '');
            if ($cbor === '') {
                respond(['error' => 'missing cbor_hex'], 400, true, $id);
            }
            $sidecar  = new SidecarClient();
            $submitted = $sidecar->submitMint($cbor);
            $txHash   = (string) ($submitted['tx_hash'] ?? '');
            if ($txHash !== '') {
                $pdo->prepare(
                    "UPDATE qd_mint_queue
                        SET tx_hash = ?, status = 'submitted', submitted_at = NOW(), updated_at = NOW()
                        WHERE id = ?"
                )->execute([$txHash, $id]);
            }
            respond($submitted, 200, true, $id);
            break;

        case 'fail':
            $msg = (string) ($body['message'] ?? 'manually marked failed');
            $pdo->prepare(
                "UPDATE qd_mint_queue SET status = 'failed', error_message = ?, updated_at = NOW() WHERE id = ?"
            )->execute([$msg, $id]);
            respond(['ok' => true], 200, $isJson, $id, 'Marked failed.', 'warn');
            break;

        case 'delete':
            if (!in_array($row['status'], ['draft', 'failed'], true)) {
                respond(['error' => 'can only delete draft or failed rows'], 400, $isJson, $id);
            }
            $pdo->prepare('DELETE FROM qd_mint_queue WHERE id = ?')->execute([$id]);
            if ($isJson) {
                respond(['ok' => true], 200, true, $id);
            }
            header('Location: /admin/mint.php?flash=' . urlencode('Row #' . $id . ' deleted.') . '&kind=ok');
            exit;

        default:
            respond(['error' => "unknown action: $action"], 400, $isJson, $id);
    }
} catch (Throwable $e) {
    respond(['error' => $e->getMessage()], 500, $isJson, $id, 'Action failed: ' . $e->getMessage(), 'error');
}

/**
 * @param array<string,mixed> $row
 * @return array<string,mixed>
 */
function callSidecarPrepare(array $row, ?string $recipient): array
{
    $sidecar = new SidecarClient();
    $cip25 = json_decode($row['cip25_json'], true) ?: [];
    $payload = [
        'rarefolio_token_id' => $row['rarefolio_token_id'],
        'collection_slug'    => $row['collection_slug'],
        'policy_id'          => $row['policy_id'] ?: null,
        'asset_name_utf8'    => @hex2bin($row['asset_name_hex']) ?: $row['asset_name_hex'],
        'recipient_addr'     => $recipient ?: 'addr_test1qq_placeholder_recipient',
        'cip25'              => $cip25,
    ];
    return $sidecar->prepareMint(array_filter($payload, fn($v) => $v !== null));
}

/**
 * Upsert a qd_tokens row from a confirmed mint queue entry.
 *
 * @param array<string,mixed> $row
 * @param array<string,mixed> $tx
 */
function upsertQdToken(PDO $pdo, array $row, array $tx): void
{
    $sql = "INSERT INTO qd_tokens
                (rarefolio_token_id, policy_id, asset_name_hex, collection_slug,
                 title, character_name, edition, mint_tx_hash, minted_at,
                 cip25_json, primary_sale_status)
            VALUES (:tid, :pol, :ahex, :coll, :title, :cname, :ed, :tx, NOW(), :js, 'minted')
            ON DUPLICATE KEY UPDATE
                mint_tx_hash        = VALUES(mint_tx_hash),
                minted_at           = VALUES(minted_at),
                cip25_json          = VALUES(cip25_json),
                primary_sale_status = 'minted',
                updated_at          = NOW()";
    $pdo->prepare($sql)->execute([
        'tid'   => $row['rarefolio_token_id'],
        'pol'   => $row['policy_id'] ?: 'PENDING',
        'ahex'  => $row['asset_name_hex'],
        'coll'  => $row['collection_slug'],
        'title' => $row['title'],
        'cname' => $row['character_name'] ?? null,
        'ed'    => $row['edition'] ?? null,
        'tx'    => $row['tx_hash'],
        'js'    => $row['cip25_json'],
    ]);
}

/**
 * Append a mint event to qd_nft_activity.
 * Silently skips if the table does not exist yet (pre-migration environments).
 *
 * @param array<string,mixed> $row  qd_mint_queue row
 * @param array<string,mixed> $tx   Blockfrost tx response
 */
function recordMintActivity(PDO $pdo, array $row, array $tx): void
{
    try {
        // Resolve qd_tokens.id for the FK
        $tokenId = $pdo->prepare('SELECT id FROM qd_tokens WHERE rarefolio_token_id = ? LIMIT 1');
        $tokenId->execute([$row['rarefolio_token_id']]);
        $nftId = $tokenId->fetchColumn();
        if (!$nftId) return;  // token row not found — skip

        $pdo->prepare(
            "INSERT INTO qd_nft_activity
                (nft_id, rarefolio_token_id, event_type, to_addr, tx_hash, note, event_at)
             VALUES
                (:nft_id, :token_id, 'mint', :to_addr, :tx_hash, :note, NOW())"
        )->execute([
            ':nft_id'   => $nftId,
            ':token_id' => $row['rarefolio_token_id'],
            ':to_addr'  => null,          // recipient stored in qd_tokens.current_owner_wallet
            ':tx_hash'  => $row['tx_hash'],
            ':note'     => 'Minted via RareFolio admin dashboard',
        ]);
    } catch (Throwable) {
        // Table may not exist yet — do not break the confirm flow
        error_log('[mint-action] qd_nft_activity insert skipped: ' . func_get_args()[2] ?? '');
    }
}

/**
 * @param array<string,mixed> $payload
 */
function respond(array $payload, int $code, bool $isJson, int $id, ?string $flash = null, string $kind = 'ok'): never
{
    http_response_code($code);
    if ($isJson) {
        header('Content-Type: application/json');
        echo json_encode($payload, JSON_UNESCAPED_SLASHES);
        exit;
    }
    $url = '/admin/mint-detail.php?id=' . $id;
    if ($flash) {
        $url .= '&flash=' . urlencode($flash) . '&kind=' . urlencode($kind);
    } elseif (isset($payload['error'])) {
        $url .= '&flash=' . urlencode((string) $payload['error']) . '&kind=error';
    }
    header('Location: ' . $url);
    exit;
}
