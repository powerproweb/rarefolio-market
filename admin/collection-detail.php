<?php
/**
 * Collection detail — manage one collection.
 *
 * Sections:
 *   1. Policy info + funding status (via sidecar)
 *   2. Royalty recipients — editable, real-time % total
 *   3. Policy lock — appears when all primary mints are confirmed
 *   4. Sweep status — live split wallet balance + recent sweep log
 *      Manual Sweep button
 */
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

use RareFolio\Sidecar\Client as SidecarClient;

$id = (int) ($_GET['id'] ?? 0);
if ($id <= 0) { http_response_code(400); echo 'missing id'; exit; }

$col = $pdo->prepare('SELECT * FROM qd_collections WHERE id = ?');
$col->execute([$id]);
$col = $col->fetch();
if (!$col) { http_response_code(404); echo 'collection not found'; exit; }

$flash     = $_GET['flash'] ?? null;
$flashKind = $_GET['kind']  ?? 'ok';

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'save_recipients') {
        // Save royalty recipients
        $rLabels = $_POST['r_label'] ?? [];
        $rAddrs  = $_POST['r_addr']  ?? [];
        $rPcts   = $_POST['r_pct']   ?? [];
        $rIds    = $_POST['r_id']    ?? [];
        try {
            $pdo->beginTransaction();
            // Delete rows not in submitted ids
            $existingIds = array_filter(array_map('intval', $rIds), fn($v) => $v > 0);
            if ($existingIds) {
                $inList = implode(',', $existingIds);
                $pdo->exec("DELETE FROM qd_royalty_recipients WHERE collection_id = $id AND id NOT IN ($inList)");
            } else {
                $pdo->prepare('DELETE FROM qd_royalty_recipients WHERE collection_id = ?')->execute([$id]);
            }
            for ($i = 0; $i < count($rLabels); $i++) {
                $rid   = (int)($rIds[$i] ?? 0);
                $label = trim((string)($rLabels[$i] ?? ''));
                $addr  = trim((string)($rAddrs[$i] ?? ''));
                $pct   = (float)($rPcts[$i] ?? 0);
                if ($label === '' || $addr === '' || $pct <= 0) continue;
                if ($rid > 0) {
                    $pdo->prepare('UPDATE qd_royalty_recipients SET label=?, wallet_addr=?, pct=?, sort_order=?, updated_at=NOW() WHERE id=? AND collection_id=?')
                        ->execute([$label, $addr, $pct, $i, $rid, $id]);
                } else {
                    $pdo->prepare('INSERT INTO qd_royalty_recipients (collection_id,label,wallet_addr,pct,sort_order) VALUES (?,?,?,?,?)')
                        ->execute([$id, $label, $addr, $pct, $i]);
                }
            }
            $pdo->commit();
            header('Location: /admin/collection-detail.php?id=' . $id . '&flash=' . urlencode('Recipients saved.') . '&kind=ok');
            exit;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $flash = 'DB error: ' . $e->getMessage(); $flashKind = 'error';
        }

    } elseif ($action === 'update_policy') {
        // Update policy_id + policy_addr from sidecar
        $sidecar = new SidecarClient();
        try {
            $info = $sidecar->getPolicyInfoForKey($col['policy_env_key']);
            $pdo->prepare('UPDATE qd_collections SET policy_id=?, policy_addr=?, updated_at=NOW() WHERE id=?')
                ->execute([$info['policy_id'] ?? null, $info['policy_addr'] ?? null, $id]);
            header('Location: /admin/collection-detail.php?id=' . $id . '&flash=' . urlencode('Policy ID and address updated from sidecar.') . '&kind=ok');
            exit;
        } catch (Throwable $e) {
            $flash = 'Sidecar error: ' . $e->getMessage(); $flashKind = 'error';
        }

    } elseif ($action === 'update_split_addr') {
        // Update split_wallet_addr from sidecar
        $sidecar = new SidecarClient();
        try {
            $envKey = $col['split_wallet_env_key'] ?: $col['policy_env_key'];
            $info   = $sidecar->getSweepBalance($envKey);
            $pdo->prepare('UPDATE qd_collections SET split_wallet_addr=?, updated_at=NOW() WHERE id=?')
                ->execute([$info['wallet_addr'] ?? null, $id]);
            header('Location: /admin/collection-detail.php?id=' . $id . '&flash=' . urlencode('Split wallet address updated.') . '&kind=ok');
            exit;
        } catch (Throwable $e) {
            $flash = 'Sidecar error: ' . $e->getMessage(); $flashKind = 'error';
        }

    } elseif ($action === 'lock_policy') {
        $slot = (int)($_POST['lock_slot'] ?? 0);
        if ($slot <= 0) { $flash = 'Invalid lock slot.'; $flashKind = 'error'; }
        else {
            $pdo->prepare('UPDATE qd_collections SET lock_slot=?, lock_status=\'pending_lock\', updated_at=NOW() WHERE id=?')
                ->execute([$slot, $id]);
            header('Location: /admin/collection-detail.php?id=' . $id . '&flash=' . urlencode('Lock slot set. Policy will be sealed after slot ' . $slot . '.') . '&kind=ok');
            exit;
        }

    } elseif ($action === 'manual_sweep') {
        $sidecar    = new SidecarClient();
        $envKey     = $col['split_wallet_env_key'] ?: $col['policy_env_key'];
        $minLovelace = (int)($col['split_min_sweep_lovelace'] ?? 20_000_000);
        $rStmt = $pdo->prepare('SELECT label, wallet_addr, pct FROM qd_royalty_recipients WHERE collection_id=? ORDER BY sort_order');
        $rStmt->execute([$id]);
        $recipients = array_map(fn($r) => ['addr' => $r['wallet_addr'], 'pct' => (float)$r['pct'], 'label' => $r['label']], $rStmt->fetchAll());
        try {
            $result = $sidecar->runSweep($envKey, $recipients, $minLovelace);
            $note = $result['swept'] ? 'Sweep complete. tx_hash: ' . ($result['tx_hash'] ?? 'n/a') : 'Not swept: ' . ($result['reason'] ?? 'balance below threshold');
            // Log it
            try {
                $pdo->prepare("INSERT INTO qd_sweep_log (collection_id, trigger_type, sweep_amount_lovelace, distributions, tx_hash, status) VALUES (?, 'manual', ?, ?, ?, ?)")
                    ->execute([$id, $result['balance_lovelace'] ?? 0, json_encode($result['distributions'] ?? []), $result['tx_hash'] ?? null, $result['tx_hash'] ? 'submitted' : 'pending']);
            } catch (Throwable) {}
            header('Location: /admin/collection-detail.php?id=' . $id . '&flash=' . urlencode($note) . '&kind=' . ($result['swept'] ? 'ok' : 'warn'));
            exit;
        } catch (Throwable $e) {
            $flash = 'Sweep error: ' . $e->getMessage(); $flashKind = 'error';
        }
    }

    // Reload fresh data after any POST
    $col = $pdo->prepare('SELECT * FROM qd_collections WHERE id = ?');
    $col->execute([$id]);
    $col = $col->fetch();
}

// Load recipients
$recipients = $pdo->prepare('SELECT * FROM qd_royalty_recipients WHERE collection_id=? ORDER BY sort_order')->execute([$id]) ?
    $pdo->prepare('SELECT * FROM qd_royalty_recipients WHERE collection_id=? ORDER BY sort_order') : null;
$rStmt = $pdo->prepare('SELECT * FROM qd_royalty_recipients WHERE collection_id=? ORDER BY sort_order');
$rStmt->execute([$id]);
$recipients = $rStmt->fetchAll();

// Load recent sweep log
$sweepLog = [];
try {
    $slStmt = $pdo->prepare('SELECT * FROM qd_sweep_log WHERE collection_id=? ORDER BY created_at DESC LIMIT 10');
    $slStmt->execute([$id]);
    $sweepLog = $slStmt->fetchAll();
} catch (Throwable) {}

// Live sidecar data (non-blocking)
$sidecar      = new SidecarClient();
$balanceData  = null;
$envKey       = $col['split_wallet_env_key'] ?: $col['policy_env_key'];
try { $balanceData = $sidecar->getSweepBalance($envKey); } catch (Throwable) {}

$pageTitle = 'Collection: ' . $col['name'] . ' — RareFolio admin';
require __DIR__ . '/includes/header.php';
?>

<div class="rf-toolbar">
    <a href="/admin/collections.php" class="rf-btn rf-btn-ghost">&larr; Collections</a>
    <div class="rf-spacer"></div>
    <span class="rf-pill rf-pill-<?= h($col['lock_status']) ?>"><?= h($col['lock_status']) ?></span>
</div>

<?php if ($flash): ?>
    <div class="rf-alert rf-alert-<?= h($flashKind) ?>"><?= h($flash) ?></div>
<?php endif; ?>

<h1><?= h($col['name']) ?></h1>
<p class="rf-mono"><?= h($col['slug']) ?> &middot; <?= h($col['network']) ?> &middot; edition <?= (int)$col['primary_minted_count'] ?>/<?= (int)$col['edition_size'] ?></p>

<!-- Policy info -->
<h2>Policy wallet</h2>
<table class="rf-table">
    <tr><th>Env key</th>    <td class="rf-mono"><?= h($col['policy_env_key']) ?> → <code>POLICY_MNEMONIC_<?= h($col['policy_env_key']) ?></code> in sidecar/.env</td></tr>
    <tr><th>Policy ID</th>  <td class="rf-mono"><?= $col['policy_id'] ? h($col['policy_id']) : '<span style="color:var(--rf-warn)">not derived yet</span>' ?></td></tr>
    <tr><th>Policy addr</th><td class="rf-mono" style="font-size:0.8rem"><?= $col['policy_addr'] ? h($col['policy_addr']) : '—' ?></td></tr>
    <tr><th>Lock slot</th>  <td class="rf-mono"><?= $col['lock_slot'] ? h((string)$col['lock_slot']) : 'none (open minting)' ?></td></tr>
    <tr><th>Royalty</th>    <td class="rf-mono"><?= number_format((float)$col['royalty_total_pct'], 2) ?>% creator + <?= number_format((float)$col['platform_fee_pct'], 2) ?>% platform</td></tr>
</table>
<form method="post" style="margin-top:0.5rem">
    <input type="hidden" name="action" value="update_policy">
    <button class="rf-btn rf-btn-ghost" type="submit">↺ Refresh policy ID from sidecar</button>
</form>

<!-- Recipients -->
<h2>Royalty recipients <small class="rf-mono" style="font-size:0.75rem;">must sum to 100%</small></h2>
<form method="post" class="rf-form">
    <input type="hidden" name="action" value="save_recipients">
    <div id="recipients-wrap">
        <?php foreach ($recipients as $i => $r): ?>
        <div class="rf-recipient-row" style="display:grid;grid-template-columns:1fr 2fr 80px auto;gap:0.5rem;margin-bottom:0.5rem;align-items:center;">
            <input type="hidden" name="r_id[]" value="<?= (int)$r['id'] ?>">
            <input type="text"   name="r_label[]" value="<?= h($r['label']) ?>"       placeholder="Label">
            <input type="text"   name="r_addr[]"  value="<?= h($r['wallet_addr']) ?>" placeholder="addr1...">
            <input type="number" name="r_pct[]"   value="<?= h($r['pct']) ?>"         step="0.0001" min="0.01" max="100">
            <button type="button" onclick="removeRow(this)" class="rf-btn rf-btn-ghost" style="color:var(--rf-error)">✕</button>
        </div>
        <?php endforeach; ?>
    </div>
    <div class="rf-toolbar">
        <button type="button" class="rf-btn rf-btn-ghost" id="btn-add">+ Add recipient</button>
        <span id="pct-total" class="rf-mono" style="font-size:0.85rem;"></span>
        <div class="rf-spacer"></div>
        <button type="submit" class="rf-btn">Save recipients</button>
    </div>
</form>

<!-- Policy lock -->
<h2>Policy lock (supply cap)</h2>
<?php if ($col['all_primary_minted'] && $col['lock_status'] === 'open'): ?>
    <div class="rf-alert rf-alert-ok">
        All <?= (int)$col['edition_size'] ?> tokens are minted. You can now seal the supply by setting a lock slot.
    </div>
    <form method="post" class="rf-form" style="max-width:600px">
        <input type="hidden" name="action" value="lock_policy">
        <label>Lock slot <small class="rf-mono">(choose a slot ~2–4 weeks in the future)</small></label>
        <div style="display:grid;grid-template-columns:1fr auto;gap:0.75rem;align-items:end;">
            <input type="number" name="lock_slot" min="1" placeholder="e.g. 123456789" required>
            <button type="submit" class="rf-btn" onclick="return confirm('Lock the policy? This is permanent — after this slot no more tokens can ever be minted under this policy.')">Set lock slot</button>
        </div>
        <p class="rf-mono" style="font-size:0.75rem;margin-top:0.4rem;">
            Calculate a slot for a target date (run in Node):<br>
            <code>node -e "const d=new Date('2030-01-01'); console.log(Math.floor((d-new Date('2022-04-01'))/1000+4924800))"</code>
        </p>
    </form>
<?php elseif ($col['lock_status'] === 'pending_lock'): ?>
    <div class="rf-alert rf-alert-warn">
        Lock pending at slot <strong><?= h((string)$col['lock_slot']) ?></strong>.
        After this slot, no further minting is possible under policy <code><?= h($col['policy_id'] ?? '…') ?></code>.
    </div>
<?php elseif ($col['lock_status'] === 'locked'): ?>
    <div class="rf-alert rf-alert-ok">Policy is locked at slot <?= h((string)$col['lock_slot']) ?>. Supply is permanently sealed.</div>
<?php else: ?>
    <p class="rf-mono" style="color:var(--rf-muted)">
        Lock is available after all <?= (int)$col['edition_size'] ?> primary mints are confirmed.
        Currently <?= (int)$col['primary_minted_count'] ?> / <?= (int)$col['edition_size'] ?> minted.
    </p>
<?php endif; ?>

<!-- Sweep wallet -->
<h2>Split wallet &amp; auto-sweep</h2>
<table class="rf-table">
    <tr><th>Env key</th>     <td class="rf-mono"><?= h($envKey) ?> → <code>SPLIT_MNEMONIC_<?= h($envKey) ?></code></td></tr>
    <tr><th>Address</th>     <td class="rf-mono" style="font-size:0.8rem"><?= $col['split_wallet_addr'] ? h($col['split_wallet_addr']) : '<span style="color:var(--rf-warn)">not set — click Refresh below</span>' ?></td></tr>
    <tr><th>Balance</th>     <td class="rf-mono"><?= $balanceData ? number_format((float)$balanceData['balance_ada'], 6) . ' ADA' : '—' ?></td></tr>
    <tr><th>Min sweep</th>   <td class="rf-mono"><?= number_format($col['split_min_sweep_lovelace'] / 1_000_000, 2) ?> ADA</td></tr>
</table>

<div class="rf-toolbar" style="margin-top:0.5rem">
    <form method="post" style="display:inline">
        <input type="hidden" name="action" value="update_split_addr">
        <button class="rf-btn rf-btn-ghost" type="submit">↺ Refresh split wallet address</button>
    </form>
    <form method="post" style="display:inline" onsubmit="return confirm('Run manual sweep now?')">
        <input type="hidden" name="action" value="manual_sweep">
        <button class="rf-btn" type="submit">▶ Manual sweep now</button>
    </form>
</div>

<?php if ($sweepLog): ?>
<h3>Recent sweep log</h3>
<table class="rf-table">
    <thead><tr><th>When</th><th>Trigger</th><th>Amount</th><th>Status</th><th>Tx</th></tr></thead>
    <tbody>
    <?php foreach ($sweepLog as $s): ?>
        <tr>
            <td class="rf-mono"><?= h($s['created_at']) ?></td>
            <td><?= h($s['trigger_type']) ?></td>
            <td class="rf-mono"><?= number_format((int)$s['sweep_amount_lovelace'] / 1_000_000, 6) ?> ADA</td>
            <td><span class="rf-pill rf-pill-<?= h($s['status']) ?>"><?= h($s['status']) ?></span></td>
            <td class="rf-mono" style="font-size:0.75rem"><?= $s['tx_hash'] ? h(substr($s['tx_hash'], 0, 12)) . '…' : '—' ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
<?php endif; ?>

<script>
function recalcTotal() {
    const inputs = document.querySelectorAll('input[name="r_pct[]"]');
    let total = 0;
    inputs.forEach(i => total += parseFloat(i.value) || 0);
    const el = document.getElementById('pct-total');
    if (!el) return;
    el.textContent = 'Total: ' + total.toFixed(4) + '% ' + (Math.abs(total - 100) < 0.01 ? '✓' : '(must equal 100)');
    el.style.color = Math.abs(total - 100) < 0.01 ? 'var(--rf-ok)' : 'var(--rf-error)';
}
document.getElementById('recipients-wrap')?.addEventListener('input', recalcTotal);
document.getElementById('btn-add')?.addEventListener('click', () => {
    const wrap = document.getElementById('recipients-wrap');
    const div  = document.createElement('div');
    div.className = 'rf-recipient-row';
    div.style.cssText = 'display:grid;grid-template-columns:1fr 2fr 80px auto;gap:0.5rem;margin-bottom:0.5rem;align-items:center;';
    div.innerHTML = `<input type="hidden" name="r_id[]" value="0">
        <input type="text" name="r_label[]" placeholder="Label">
        <input type="text" name="r_addr[]" placeholder="addr1...">
        <input type="number" name="r_pct[]" placeholder="%" step="0.0001" min="0.01" max="100">
        <button type="button" onclick="removeRow(this)" class="rf-btn rf-btn-ghost" style="color:var(--rf-error)">✕</button>`;
    wrap.appendChild(div);
    recalcTotal();
});
function removeRow(btn) { btn.closest('.rf-recipient-row').remove(); recalcTotal(); }
recalcTotal();
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
