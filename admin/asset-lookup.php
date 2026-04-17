<?php
/**
 * Asset lookup: given a Blockfrost `unit` (policy_id + asset_name_hex) or
 * a `policy_id`, fetch via the sidecar and display the result.
 *
 * Useful for:
 *   - verifying a minted asset exists on-chain
 *   - finding the current owner of a pre-marketplace sale
 *   - backfilling qd_tokens rows manually
 */
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

use RareFolio\Sidecar\Client as SidecarClient;
use RareFolio\Blockfrost\Client as BlockfrostClient;

$q      = trim((string) ($_GET['q'] ?? ''));
$mode   = (string) ($_GET['mode'] ?? 'asset'); // asset | policy
$result = null;
$error  = null;

if ($q !== '') {
    try {
        if ($mode === 'policy') {
            if (!preg_match('/^[0-9a-f]{56}$/i', $q)) {
                throw new RuntimeException('policy_id must be exactly 56 hex chars.');
            }
            $bf = new BlockfrostClient();
            $result = ['policy_id' => $q, 'assets' => $bf->assetsByPolicy($q, 1, 100)];
        } else {
            if (!preg_match('/^[0-9a-f]{56,}$/i', $q)) {
                throw new RuntimeException('unit must be hex, 56+ chars (policy_id + asset_name_hex).');
            }
            // Prefer sidecar (gives us decoded CIP-25 + current owner in one call)
            $sidecar = new SidecarClient();
            if ($sidecar->health()) {
                $result = $sidecar->asset($q);
                if ($result === null) {
                    $error = 'Asset not found on ' . (\RareFolio\Config::get('BLOCKFROST_NETWORK', 'preprod'));
                }
            } else {
                // Fallback: raw Blockfrost call from PHP
                $bf    = new BlockfrostClient();
                $asset = $bf->asset($q);
                $owner = $bf->currentOwner($q);
                $result = $asset ? ($asset + ['current_owner' => $owner]) : null;
                if ($result === null) {
                    $error = 'Asset not found.';
                }
            }
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$pageTitle = 'Asset lookup — RareFolio admin';
require __DIR__ . '/includes/header.php';
?>

<h1>Asset lookup</h1>
<p class="rf-mono">Fetches directly from Blockfrost via the sidecar (falls back to PHP client if sidecar is down).</p>

<form method="get" class="rf-form" style="max-width:900px">
    <div style="display:grid; grid-template-columns: 180px 1fr auto; gap:0.75rem;">
        <select name="mode">
            <option value="asset"  <?= $mode === 'asset'  ? 'selected' : '' ?>>Asset (unit)</option>
            <option value="policy" <?= $mode === 'policy' ? 'selected' : '' ?>>Policy (policy_id)</option>
        </select>
        <input type="text" name="q" value="<?= h($q) ?>"
               placeholder="policy_id + asset_name_hex (or 56-hex policy id)">
        <button class="rf-btn" type="submit">Look up</button>
    </div>
</form>

<?php if ($error): ?>
    <div class="rf-alert rf-alert-error"><?= h($error) ?></div>
<?php endif; ?>

<?php if ($result !== null): ?>
    <h2>Result</h2>
    <pre class="rf-code"><?= h(json_encode(
        $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
    )) ?></pre>
<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>
