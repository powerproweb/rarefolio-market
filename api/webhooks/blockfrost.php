<?php
/**
 * Public inbound Blockfrost webhook endpoint.
 *
 * Blockfrost calls this URL when any registered address receives a transaction.
 * This is how the auto-sweep is triggered:
 *
 *   Blockfrost POST → this file
 *     → validate HMAC signature
 *     → find which collection owns the deposit address
 *     → fetch recipients from qd_royalty_recipients
 *     → call sidecar POST /sweep/run
 *     → log result to qd_sweep_log
 *     → return HTTP 200 immediately
 *
 * Registration:
 *   Go to https://blockfrost.io/dashboard/webhooks → "Create webhook"
 *   Type: Transaction / Address Activity
 *   Trigger address: your split wallet address (from qd_collections.split_wallet_addr)
 *   Endpoint URL: https://rarefolio.io/api/webhooks/blockfrost.php
 *   Auth token → copy into BLOCKFROST_WEBHOOK_AUTH_TOKEN in .env
 *
 * Security: validates HMAC-SHA256 signature before doing anything.
 * Returns 200 even on sweep errors so Blockfrost does not retry endlessly.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../src/Config.php';
require_once __DIR__ . '/../../src/Db.php';
require_once __DIR__ . '/../../src/Sidecar/Client.php';
require_once __DIR__ . '/../../src/Webhook/BlockfrostReceiver.php';

use RareFolio\Config;
use RareFolio\Db;
use RareFolio\Sidecar\Client as SidecarClient;
use RareFolio\Webhook\BlockfrostReceiver;

Config::load(__DIR__ . '/../../.env');

// Always return JSON
header('Content-Type: application/json');

// -----------------------------------------------------------------------
// 1. Read and validate the request
// -----------------------------------------------------------------------
$rawBody   = (string) file_get_contents('php://input');
$sigHeader = $_SERVER['HTTP_BLOCKFROST_SIGNATURE'] ?? '';
$secret    = (string) Config::get('BLOCKFROST_WEBHOOK_AUTH_TOKEN', '');

if ($secret === '') {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'BLOCKFROST_WEBHOOK_AUTH_TOKEN not configured']);
    exit;
}

if (!BlockfrostReceiver::validate($secret, $rawBody, $sigHeader)) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'invalid signature']);
    exit;
}

// -----------------------------------------------------------------------
// 2. Parse payload
// -----------------------------------------------------------------------
$payload = json_decode($rawBody, true);
if (!is_array($payload)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'invalid JSON body']);
    exit;
}

// Respond 200 immediately — Blockfrost requires fast acknowledgement
// Sweep happens synchronously below (acceptable for small distributions)
http_response_code(200);

// -----------------------------------------------------------------------
// 3. Find the collection matching the deposit address
// -----------------------------------------------------------------------
$receivedAddrs = BlockfrostReceiver::extractReceivedAddresses($payload);
if (empty($receivedAddrs)) {
    echo json_encode(['ok' => true, 'note' => 'no Cardano addresses in payload']);
    exit;
}

try {
    $pdo = Db::pdo();
} catch (Throwable $e) {
    error_log('[bf-webhook] DB error: ' . $e->getMessage());
    echo json_encode(['ok' => true, 'note' => 'db unavailable']);
    exit;
}

// Find collection(s) whose split_wallet_addr matches any received address
$placeholders = implode(',', array_fill(0, count($receivedAddrs), '?'));
$stmt = $pdo->prepare("
    SELECT id, slug, split_wallet_env_key, split_min_sweep_lovelace
    FROM qd_collections
    WHERE split_wallet_addr IN ($placeholders)
    LIMIT 5
");
$stmt->execute($receivedAddrs);
$collections = $stmt->fetchAll();

if (empty($collections)) {
    echo json_encode(['ok' => true, 'note' => 'deposit address not matched to any collection']);
    exit;
}

// -----------------------------------------------------------------------
// 4. Trigger sweep for each matched collection
// -----------------------------------------------------------------------
$sidecar = new SidecarClient();
$results = [];

foreach ($collections as $col) {
    $envKey     = (string) ($col['split_wallet_env_key'] ?? '');
    $minLovelace = (int)   ($col['split_min_sweep_lovelace'] ?? 20_000_000);
    $collectionId = (int)  $col['id'];

    if ($envKey === '') {
        $results[] = ['collection' => $col['slug'], 'skipped' => 'no split_wallet_env_key'];
        continue;
    }

    // Fetch recipients
    $rStmt = $pdo->prepare(
        'SELECT label, wallet_addr, pct FROM qd_royalty_recipients
          WHERE collection_id = ? ORDER BY sort_order ASC'
    );
    $rStmt->execute([$collectionId]);
    $recipients = $rStmt->fetchAll();

    if (empty($recipients)) {
        $results[] = ['collection' => $col['slug'], 'skipped' => 'no recipients configured'];
        continue;
    }

    // Format for sidecar
    $rcpForSidecar = array_map(fn($r) => [
        'addr'  => $r['wallet_addr'],
        'pct'   => (float) $r['pct'],
        'label' => $r['label'],
    ], $recipients);

    // Log sweep attempt
    $logStmt = $pdo->prepare(
        "INSERT INTO qd_sweep_log
            (collection_id, trigger_type, sweep_amount_lovelace, distributions, status)
         VALUES (?, 'blockfrost_webhook', 0, '[]', 'pending')"
    );
    $logStmt->execute([$collectionId]);
    $logId = (int) $pdo->lastInsertId();

    try {
        $result = $sidecar->runSweep($envKey, $rcpForSidecar, $minLovelace);

        // Update log
        $pdo->prepare(
            "UPDATE qd_sweep_log
                SET sweep_amount_lovelace = ?,
                    distributions = ?,
                    tx_hash = ?,
                    status = ?,
                    updated_at = NOW()
                WHERE id = ?"
        )->execute([
            $result['balance_lovelace'] ?? 0,
            json_encode($result['distributions'] ?? []),
            $result['tx_hash'] ?? null,
            isset($result['tx_hash']) ? 'submitted' : ($result['swept'] ? 'submitted' : 'pending'),
            $logId,
        ]);

        $results[] = [
            'collection' => $col['slug'],
            'swept'      => $result['swept'] ?? false,
            'tx_hash'    => $result['tx_hash'] ?? null,
            'reason'     => $result['reason'] ?? null,
        ];
    } catch (Throwable $e) {
        error_log('[bf-webhook] sweep failed for ' . $col['slug'] . ': ' . $e->getMessage());
        $pdo->prepare(
            "UPDATE qd_sweep_log SET status = 'failed', error_msg = ?, updated_at = NOW() WHERE id = ?"
        )->execute([$e->getMessage(), $logId]);
        $results[] = ['collection' => $col['slug'], 'error' => $e->getMessage()];
    }
}

echo json_encode(['ok' => true, 'results' => $results]);
