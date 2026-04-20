<?php
/**
 * New Collection wizard.
 *
 * Creates a row in qd_collections and the initial qd_royalty_recipients rows.
 * Does NOT generate mnemonics (those must be added to sidecar/.env manually).
 * The "Derive policy" button calls the sidecar to get the policy_id + addr once
 * the mnemonic is in place.
 */
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

use RareFolio\Sidecar\Client as SidecarClient;

$errors = [];
$ok     = null;

$form = [
    'name'                   => '',
    'slug'                   => '',
    'description'            => '',
    'network'                => 'preprod',
    'edition_size'           => '8',
    'policy_env_key'         => '',
    'royalty_total_pct'      => '8',
    'platform_fee_pct'       => '2.5',
    'split_wallet_env_key'   => '',
    'split_min_sweep_ada'    => '20',
];

// Recipients come as parallel arrays from the form
/** @var array<int,array{label:string,addr:string,pct:string}> */
$recipients = [
    ['label' => '', 'addr' => '', 'pct' => '100'],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach ($form as $k => $_) {
        $form[$k] = trim((string) ($_POST[$k] ?? ''));
    }

    // Parse recipient rows
    $rLabels = $_POST['r_label'] ?? [];
    $rAddrs  = $_POST['r_addr']  ?? [];
    $rPcts   = $_POST['r_pct']   ?? [];
    $recipients = [];
    for ($i = 0; $i < count($rLabels); $i++) {
        $recipients[] = [
            'label' => trim((string)($rLabels[$i] ?? '')),
            'addr'  => trim((string)($rAddrs[$i]  ?? '')),
            'pct'   => trim((string)($rPcts[$i]   ?? '')),
        ];
    }

    // Validate
    if ($form['name'] === '')            $errors[] = 'Name is required.';
    if ($form['slug'] === '')            $errors[] = 'Slug is required.';
    if (!preg_match('/^[a-z0-9-]+$/', $form['slug']))
                                         $errors[] = 'Slug must be lowercase letters, numbers, and hyphens only.';
    if ($form['policy_env_key'] === '')  $errors[] = 'Policy env key is required (e.g. FOUNDERS).';
    if (!preg_match('/^[A-Z0-9_]+$/i', $form['policy_env_key']))
                                         $errors[] = 'Policy env key must be letters, numbers, underscores only.';
    if ((int)$form['edition_size'] < 1)  $errors[] = 'Edition size must be at least 1.';

    $totalPct = array_sum(array_column($recipients, 'pct'));
    if (abs($totalPct - 100) > 0.01)     $errors[] = 'Recipient percentages must sum to exactly 100 (got ' . $totalPct . ').';

    foreach ($recipients as $i => $r) {
        if ($r['label'] === '')          $errors[] = "Recipient #" . ($i+1) . ": label is required.";
        if ($r['addr'] === '')           $errors[] = "Recipient #" . ($i+1) . ": wallet address is required.";
        if (!is_numeric($r['pct']) || (float)$r['pct'] <= 0)
                                         $errors[] = "Recipient #" . ($i+1) . ": percentage must be a positive number.";
    }

    if ($errors === []) {
        try {
            $pdo->beginTransaction();

            $pdo->prepare("
                INSERT INTO qd_collections
                    (slug, name, description, network, policy_env_key,
                     royalty_total_pct, platform_fee_pct,
                     split_wallet_env_key, split_min_sweep_lovelace, edition_size)
                VALUES (?, ?, ?, ?, ?,  ?, ?,  ?, ?, ?)
            ")->execute([
                $form['slug'],
                $form['name'],
                $form['description'] ?: null,
                $form['network'],
                strtoupper($form['policy_env_key']),
                (float)$form['royalty_total_pct'],
                (float)$form['platform_fee_pct'],
                $form['split_wallet_env_key'] !== '' ? strtoupper($form['split_wallet_env_key']) : strtoupper($form['policy_env_key']),
                (int)((float)$form['split_min_sweep_ada'] * 1_000_000),
                (int)$form['edition_size'],
            ]);

            $collectionId = (int) $pdo->lastInsertId();

            foreach ($recipients as $i => $r) {
                $pdo->prepare("
                    INSERT INTO qd_royalty_recipients (collection_id, label, wallet_addr, pct, sort_order)
                    VALUES (?, ?, ?, ?, ?)
                ")->execute([$collectionId, $r['label'], $r['addr'], (float)$r['pct'], $i]);
            }

            $pdo->commit();
            $ok = $collectionId;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $errors[] = 'DB error: ' . $e->getMessage();
        }
    }
}

$pageTitle = 'New collection — RareFolio admin';
require __DIR__ . '/includes/header.php';
?>

<div class="rf-toolbar">
    <a href="/admin/collections.php" class="rf-btn rf-btn-ghost">&larr; Collections</a>
</div>

<h1>New collection</h1>

<?php if ($ok !== null): ?>
    <div class="rf-alert rf-alert-ok">
        Collection created (ID #<?= $ok ?>).
        <a href="/admin/collection-detail.php?id=<?= $ok ?>">Open detail page &rarr;</a>
    </div>
<?php endif; ?>

<?php foreach ($errors as $e): ?>
    <div class="rf-alert rf-alert-error"><?= h($e) ?></div>
<?php endforeach; ?>

<form method="post" class="rf-form">

    <h2>Identity</h2>
    <div style="display:grid; grid-template-columns: 1fr 1fr; gap:1rem;">
        <div>
            <label>Collection name *</label>
            <input type="text" name="name" value="<?= h($form['name']) ?>"
                   placeholder="Founders Block 88 — Silver Bar I"
                   oninput="autoSlug(this.value)" required>
        </div>
        <div>
            <label>Slug * <small class="rf-mono">(must match qd_tokens.collection_slug)</small></label>
            <input type="text" name="slug" id="slug-field" value="<?= h($form['slug']) ?>"
                   placeholder="silverbar-01-founders" required>
        </div>
    </div>
    <div>
        <label>Description</label>
        <textarea name="description"><?= h($form['description']) ?></textarea>
    </div>
    <div style="display:grid; grid-template-columns: 1fr 1fr 1fr; gap:1rem;">
        <div>
            <label>Network</label>
            <select name="network">
                <option value="preprod" <?= $form['network'] === 'preprod' ? 'selected' : '' ?>>preprod (testing)</option>
                <option value="mainnet" <?= $form['network'] === 'mainnet' ? 'selected' : '' ?>>mainnet</option>
            </select>
        </div>
        <div>
            <label>Edition size (total tokens)</label>
            <input type="number" name="edition_size" value="<?= h($form['edition_size']) ?>" min="1" required>
        </div>
    </div>

    <h2>Policy wallet <small class="rf-mono" style="font-size:0.75rem;">POLICY_MNEMONIC_{KEY} in sidecar/.env</small></h2>
    <p class="rf-mono" style="font-size:0.8rem;">
        The env key determines which mnemonic the sidecar reads. e.g. key <code>FOUNDERS</code> reads <code>POLICY_MNEMONIC_FOUNDERS</code>.
        Add the mnemonic to <code>sidecar/.env</code> BEFORE clicking "Derive policy".
    </p>
    <div style="display:grid; grid-template-columns: 1fr auto; gap:0.75rem; align-items:end;">
        <div>
            <label>Policy env key * <small>(UPPERCASE, no spaces)</small></label>
            <input type="text" name="policy_env_key" id="env-key" value="<?= h($form['policy_env_key']) ?>"
                   placeholder="FOUNDERS" style="text-transform:uppercase" required>
        </div>
        <button type="button" class="rf-btn" id="btn-derive">Derive policy from sidecar</button>
    </div>
    <pre class="rf-code" id="derive-output" style="margin-top:0.5rem">(click "Derive policy" to get the policy_id and funding address)</pre>

    <h2>Royalties &amp; fees</h2>
    <div style="display:grid; grid-template-columns: 1fr 1fr; gap:1rem;">
        <div>
            <label>Total creator royalty % (on secondary sales)</label>
            <input type="number" name="royalty_total_pct" value="<?= h($form['royalty_total_pct']) ?>"
                   step="0.01" min="0" max="50" required>
        </div>
        <div>
            <label>Platform fee %</label>
            <input type="number" name="platform_fee_pct" value="<?= h($form['platform_fee_pct']) ?>"
                   step="0.01" min="0" max="20" required>
        </div>
    </div>

    <h2>Split wallet <small class="rf-mono" style="font-size:0.75rem;">SPLIT_MNEMONIC_{KEY} in sidecar/.env</small></h2>
    <p class="rf-mono" style="font-size:0.8rem;">
        Receives sale proceeds and auto-distributes to recipients below.
        Leave blank to use the same key as the policy wallet.
    </p>
    <div style="display:grid; grid-template-columns: 1fr 1fr; gap:1rem;">
        <div>
            <label>Split wallet env key <small>(blank = same as policy key)</small></label>
            <input type="text" name="split_wallet_env_key" value="<?= h($form['split_wallet_env_key']) ?>"
                   placeholder="FOUNDERS (or leave blank)" style="text-transform:uppercase">
        </div>
        <div>
            <label>Minimum ADA before auto-sweep</label>
            <input type="number" name="split_min_sweep_ada" value="<?= h($form['split_min_sweep_ada']) ?>"
                   step="1" min="5">
        </div>
    </div>

    <h2>Royalty recipients <small class="rf-mono" style="font-size:0.75rem;">must sum to 100%</small></h2>
    <p class="rf-mono" style="font-size:0.8rem;">
        These % values are shares of the creator royalty pool.
        e.g. if royalty = 8% and Juan = 60%, Juan gets 4.8% of every secondary sale.
    </p>

    <div id="recipients-wrap">
        <?php foreach ($recipients as $i => $r): ?>
        <div class="rf-recipient-row" style="display:grid; grid-template-columns: 1fr 2fr 80px auto; gap:0.5rem; margin-bottom:0.5rem; align-items:center;">
            <input type="text" name="r_label[]" value="<?= h($r['label']) ?>" placeholder="Label (e.g. Juan Jose)">
            <input type="text" name="r_addr[]"  value="<?= h($r['addr']) ?>"  placeholder="addr1...">
            <input type="number" name="r_pct[]" value="<?= h($r['pct']) ?>"  placeholder="%" step="0.01" min="0.01" max="100">
            <button type="button" onclick="removeRow(this)" class="rf-btn rf-btn-ghost" style="color:var(--rf-error)">✕</button>
        </div>
        <?php endforeach; ?>
    </div>

    <div class="rf-toolbar" style="margin-bottom:1.5rem">
        <button type="button" class="rf-btn rf-btn-ghost" id="btn-add-row">+ Add recipient</button>
        <span id="pct-total" class="rf-mono" style="font-size:0.85rem;"></span>
    </div>

    <div class="rf-toolbar">
        <button type="submit" class="rf-btn">Create collection</button>
        <a href="/admin/collections.php" class="rf-btn rf-btn-ghost">Cancel</a>
    </div>
</form>

<script>
// Auto-generate slug from name
function autoSlug(name) {
    const slugField = document.getElementById('slug-field');
    if (!slugField._touched) {
        slugField.value = name.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '');
    }
}
document.getElementById('slug-field').addEventListener('input', () => {
    document.getElementById('slug-field')._touched = true;
});

// Derive policy from sidecar
document.getElementById('btn-derive').addEventListener('click', async () => {
    const key = document.getElementById('env-key').value.trim().toUpperCase();
    if (!key) { alert('Enter a policy env key first.'); return; }
    const out = document.getElementById('derive-output');
    out.textContent = 'Calling sidecar…';
    try {
        const r = await fetch(`/admin/sidecar-proxy.php?path=/mint/policy-id%3Fenv_key=${encodeURIComponent(key)}`);
        const j = await r.json();
        out.textContent = JSON.stringify(j, null, 2);
        if (j.policy_addr) {
            out.textContent += '\n\n→ Fund this address with ≥ 5 ADA before minting:\n   ' + j.policy_addr;
        }
    } catch(e) {
        out.textContent = 'Error: ' + e.message + '\n(Is the sidecar running? Is POLICY_MNEMONIC_' + key + ' set in sidecar/.env?)';
    }
});

// Add / remove recipient rows
function recalcTotal() {
    const inputs = document.querySelectorAll('input[name="r_pct[]"]');
    let total = 0;
    inputs.forEach(i => total += parseFloat(i.value) || 0);
    const el = document.getElementById('pct-total');
    el.textContent = 'Total: ' + total.toFixed(4) + '% ' + (Math.abs(total - 100) < 0.01 ? '✓' : '(must equal 100)');
    el.style.color = Math.abs(total - 100) < 0.01 ? 'var(--rf-ok)' : 'var(--rf-error)';
}
document.getElementById('recipients-wrap').addEventListener('input', recalcTotal);

document.getElementById('btn-add-row').addEventListener('click', () => {
    const wrap = document.getElementById('recipients-wrap');
    const div = document.createElement('div');
    div.className = 'rf-recipient-row';
    div.style.cssText = 'display:grid;grid-template-columns:1fr 2fr 80px auto;gap:0.5rem;margin-bottom:0.5rem;align-items:center;';
    div.innerHTML = `
        <input type="text" name="r_label[]" placeholder="Label">
        <input type="text" name="r_addr[]"  placeholder="addr1...">
        <input type="number" name="r_pct[]" placeholder="%" step="0.01" min="0.01" max="100">
        <button type="button" onclick="removeRow(this)" class="rf-btn rf-btn-ghost" style="color:var(--rf-error)">✕</button>`;
    wrap.appendChild(div);
    recalcTotal();
});

function removeRow(btn) {
    btn.closest('.rf-recipient-row').remove();
    recalcTotal();
}
recalcTotal();
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
