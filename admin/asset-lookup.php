<?php
/**
 * Asset lookup + Ownership Sync.
 *
 * Modes:
 *   asset  — look up a single asset by unit (policy_id + asset_name_hex)
 *   policy — list all assets under a policy_id
 *   sync   — fetch current owner from chain via sidecar and update qd_tokens
 */
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

use RareFolio\Sidecar\Client as SidecarClient;
use RareFolio\Blockfrost\Client as BlockfrostClient;

$q       = trim((string) ($_GET['q'] ?? ''));
$mode    = (string) ($_GET['mode'] ?? 'asset'); // asset | policy | sync
$result  = null;
$error   = null;
$syncMsg = null;

if ($q !== '') {
    try {
        if ($mode === 'policy') {
            if (!preg_match('/^[0-9a-f]{56}$/i', $q)) {
                throw new RuntimeException('policy_id must be exactly 56 hex chars.');
            }
            $bf = new BlockfrostClient();
            $result = ['policy_id' => $q, 'assets' => $bf->assetsByPolicy($q, 1, 100)];

        } elseif ($mode === 'sync') {
            if (!preg_match('/^[0-9a-f]{56,}$/i', $q)) {
                throw new RuntimeException('unit must be hex, 56+ chars (policy_id + asset_name_hex).');
            }
            $sidecar = new SidecarClient();
            $chain   = $sidecar->syncToken($q);
            if ($chain === null) {
                $error = 'Asset not found on chain — cannot sync.';
            } else {
                $result = $chain;
                $newOwner = $chain['current_owner'] ?? null;

                // Update qd_tokens if we have a match
                $stmt = $pdo->prepare(
                    'SELECT id, current_owner_wallet, rarefolio_token_id FROM qd_tokens
                     WHERE policy_id = :pol AND asset_name_hex = :ahex LIMIT 1'
                );
                $stmt->execute([
                    ':pol'  => $chain['policy_id'],
                    ':ahex' => $chain['asset_name'] ?? substr($q, 56),
                ]);
                $tokenRow = $stmt->fetch();

                if ($tokenRow && $newOwner !== null) {
                    $oldOwner = $tokenRow['current_owner_wallet'];
                    $pdo->prepare(
                        'UPDATE qd_tokens SET current_owner_wallet = ?, updated_at = NOW() WHERE id = ?'
                    )->execute([$newOwner, $tokenRow['id']]);

                    // Log a transfer event if owner changed (best-effort)
                    if ($oldOwner !== $newOwner) {
                        try {
                            $pdo->prepare(
                                "INSERT INTO qd_nft_activity
                                    (nft_id, rarefolio_token_id, event_type, from_addr, to_addr,
                                     tx_hash, note, event_at)
                                 VALUES (?, ?, 'transfer', ?, ?, NULL, 'Ownership sync via admin asset-lookup', NOW())"
                            )->execute([
                                $tokenRow['id'],
                                $tokenRow['rarefolio_token_id'],
                                $oldOwner,
                                $newOwner,
                            ]);
                        } catch (Throwable) { /* table may not exist yet */ }
                    }

                    $changed  = $oldOwner !== $newOwner;
                    $syncMsg  = $changed
                        ? 'Owner updated: ' . substr((string)$oldOwner, 0, 14) . '… → ' . substr((string)$newOwner, 0, 14) . '…'
                        : 'Owner unchanged — qd_tokens already up to date.';
                } elseif ($tokenRow && $newOwner === null) {
                    $syncMsg = 'Chain returned no owner (NFT may be in escrow or burned). qd_tokens not updated.';
                } else {
                    $syncMsg = 'No qd_tokens row matched policy_id + asset_name_hex. DB not updated.';
                }
            }

        } else {
            // mode === 'asset' (default)
            if (!preg_match('/^[0-9a-f]{56,}$/i', $q)) {
                throw new RuntimeException('unit must be hex, 56+ chars (policy_id + asset_name_hex).');
            }
            $sidecar = new SidecarClient();
            if ($sidecar->health()) {
                $result = $sidecar->asset($q);
                if ($result === null) {
                    $error = 'Asset not found on ' . (\RareFolio\Config::get('BLOCKFROST_NETWORK', 'preprod'));
                }
            } else {
                $bf    = new BlockfrostClient();
                $asset = $bf->asset($q);
                $owner = $bf->currentOwner($q);
                $result = $asset ? ($asset + ['current_owner' => $owner]) : null;
                if ($result === null) $error = 'Asset not found.';
            }
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$pageTitle = 'Asset lookup — RareFolio admin';
require __DIR__ . '/includes/header.php';
?>

<h1>Asset lookup &amp; ownership sync</h1>
<p class="rf-mono">Fetches from Blockfrost via the sidecar. <strong>Sync mode</strong> also updates <code>qd_tokens.current_owner_wallet</code>.</p>

<form method="get" class="rf-form" style="max-width:900px">
    <div style="display:grid; grid-template-columns: 180px 1fr auto; gap:0.75rem;">
        <select name="mode">
            <option value="asset"  <?= $mode === 'asset'  ? 'selected' : '' ?>>Asset (unit)</option>
            <option value="policy" <?= $mode === 'policy' ? 'selected' : '' ?>>Policy (policy_id)</option>
            <option value="sync"   <?= $mode === 'sync'   ? 'selected' : '' ?>>Ownership sync (unit)</option>
        </select>
        <input type="text" name="q" value="<?= h($q) ?>"
               placeholder="policy_id + asset_name_hex (or 56-hex policy id)">
        <button class="rf-btn" type="submit">Go</button>
    </div>
</form>

<?php if ($error): ?>
    <div class="rf-alert rf-alert-error"><?= h($error) ?></div>
<?php endif; ?>

<?php if ($syncMsg !== null): ?>
    <div class="rf-alert rf-alert-ok"><?= h($syncMsg) ?></div>
<?php endif; ?>

<?php if ($result !== null): ?>
    <h2>Result</h2>
    <pre class="rf-code"><?= h(json_encode(
        $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
    )) ?></pre>
<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>
