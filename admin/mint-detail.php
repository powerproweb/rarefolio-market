<?php
/**
 * Mint queue — detail view for a single row.
 *
 * Actions (via mint-action.php):
 *   - Prepare payload (sidecar /mint/prepare) and render in JSON viewer
 *   - Mark signed (stub, until Phase 2 tx builder)
 *   - Mark submitted (stub)
 *   - Mark confirmed (calls Blockfrost tx lookup when tx_hash present)
 *   - Mark failed
 *   - Delete draft
 */
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

$id = (int) ($_GET['id'] ?? 0);
if ($id <= 0) {
    http_response_code(400);
    echo 'missing id';
    exit;
}

$stmt = $pdo->prepare('SELECT * FROM qd_mint_queue WHERE id = ?');
$stmt->execute([$id]);
$row = $stmt->fetch();
if (!$row) {
    http_response_code(404);
    echo 'not found';
    exit;
}

$flash = $_GET['flash'] ?? null;
$flashKind = $_GET['kind'] ?? 'ok';

$pageTitle = 'Mint #' . $row['id'] . ' — RareFolio';
require __DIR__ . '/includes/header.php';
?>

<div class="rf-toolbar">
    <a href="/admin/mint.php" class="rf-btn rf-btn-ghost">&larr; Back to queue</a>
    <div class="rf-spacer"></div>
    <span class="rf-pill rf-pill-<?= h($row['status']) ?>"><?= h($row['status']) ?></span>
</div>

<?php if ($flash): ?>
    <div class="rf-alert rf-alert-<?= h($flashKind) ?>"><?= h($flash) ?></div>
<?php endif; ?>

<h1><?= h($row['title']) ?> <small class="rf-mono">#<?= (int)$row['id'] ?></small></h1>
<p class="rf-mono">
    token_id: <strong><?= h($row['rarefolio_token_id']) ?></strong> ·
    collection: <?= h($row['collection_slug']) ?> ·
    edition: <?= h($row['edition'] ?? '—') ?>
    <?php if (!empty($row['character_name'])): ?>
        · character: <?= h($row['character_name']) ?>
    <?php endif; ?>
</p>

<h2>On-chain identifiers</h2>
<table class="rf-table">
    <tr><th>policy_id</th>     <td class="rf-mono"><?= h($row['policy_id'] ?? '(not yet assigned)') ?></td></tr>
    <tr><th>asset_name_hex</th><td class="rf-mono"><?= h($row['asset_name_hex']) ?></td></tr>
    <tr><th>asset_name_utf8</th><td class="rf-mono"><?= h(@hex2bin($row['asset_name_hex']) ?: '') ?></td></tr>
    <tr><th>image CID</th>     <td class="rf-mono"><?= h($row['image_ipfs_cid'] ?? '—') ?></td></tr>
    <tr><th>royalty token</th> <td><?= $row['royalty_token_ok'] ? '<span style="color:var(--rf-ok)">locked</span>' : '<span style="color:var(--rf-warn)">not locked</span>' ?></td></tr>
    <tr><th>tx_hash</th>       <td class="rf-mono"><?= h($row['tx_hash'] ?? '—') ?></td></tr>
    <tr><th>attempts</th>      <td><?= (int)$row['attempts'] ?></td></tr>
    <?php if (!empty($row['error_message'])): ?>
        <tr><th>error</th><td style="color:var(--rf-error)"><?= h($row['error_message']) ?></td></tr>
    <?php endif; ?>
</table>

<h2>CIP-25 metadata (label 721)</h2>
<pre class="rf-code" id="cip25-json"><?= h(json_encode(
    json_decode($row['cip25_json'], true),
    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
)) ?></pre>

<h2>Actions</h2>
<div class="rf-toolbar">
    <form method="post" action="/admin/mint-action.php" style="display:inline">
        <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
        <input type="hidden" name="action" value="prepare">
        <button class="rf-btn" type="submit" <?= $row['status'] !== 'ready' ? 'disabled' : '' ?>>
            1) Ask sidecar to prepare
        </button>
    </form>
    <button class="rf-btn" id="btn-sign" <?= $row['status'] !== 'ready' ? 'disabled' : '' ?>>
        2) Sign with CIP-30 wallet
    </button>
    <form method="post" action="/admin/mint-action.php" style="display:inline">
        <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
        <input type="hidden" name="action" value="confirm">
        <button class="rf-btn rf-btn-ghost" type="submit" <?= empty($row['tx_hash']) ? 'disabled' : '' ?>>
            3) Check confirmation
        </button>
    </form>
    <div class="rf-spacer"></div>
    <form method="post" action="/admin/mint-action.php" style="display:inline"
          onsubmit="return confirm('Mark as failed?')">
        <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
        <input type="hidden" name="action" value="fail">
        <button class="rf-btn rf-btn-ghost" type="submit">Mark failed</button>
    </form>
    <?php if ($row['status'] === 'draft' || $row['status'] === 'failed'): ?>
        <form method="post" action="/admin/mint-action.php" style="display:inline"
              onsubmit="return confirm('Delete this queued mint? This cannot be undone.')">
            <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
            <input type="hidden" name="action" value="delete">
            <button class="rf-btn rf-btn-ghost" type="submit" style="color:var(--rf-error)">Delete</button>
        </form>
    <?php endif; ?>
</div>

<h3>Sidecar response</h3>
<pre class="rf-code" id="sidecar-output">(no request yet — click "1) Ask sidecar to prepare")</pre>

<h3>Wallet sign result</h3>
<pre class="rf-code" id="sign-output">(no signature yet — click "2) Sign with CIP-30 wallet")</pre>

<script>
/**
 * CIP-30 wallet sign flow (Phase 1 stub-aware).
 *
 * Phase 1: the sidecar returns a stub envelope (no real cbor_hex).
 *          We simulate the signing by calling wallet.getUsedAddresses()
 *          and posting the result back to the server for record-keeping.
 *
 * Phase 2: sidecar returns { cbor_hex }, and this code calls
 *          wallet.signTx(cbor_hex, true) then wallet.submitTx(signed).
 */
(function () {
    const btn    = document.getElementById('btn-sign');
    const outSc  = document.getElementById('sidecar-output');
    const outSig = document.getElementById('sign-output');
    const rowId  = <?= (int)$row['id'] ?>;

    async function pickWallet() {
        const candidates = ['nami', 'eternl', 'lace', 'flint', 'typhon', 'yoroi'];
        const cardano = window.cardano;
        if (!cardano) throw new Error('No CIP-30 wallet detected. Install Nami, Eternl, Lace, etc.');
        for (const key of candidates) {
            if (cardano[key]) return { key, api: await cardano[key].enable() };
        }
        // fall back: pick the first available provider
        const anyKey = Object.keys(cardano).find(k => typeof cardano[k]?.enable === 'function');
        if (!anyKey) throw new Error('No CIP-30 compatible wallet found.');
        return { key: anyKey, api: await cardano[anyKey].enable() };
    }

    async function fetchSidecarPayload() {
        // Reload the detail page row's latest sidecar payload from its most recent action
        const resp = await fetch(`/admin/mint-action.php?id=${rowId}&action=prepare`, { method: 'POST' });
        if (!resp.ok) throw new Error(`prepare failed: ${resp.status}`);
        return resp.json();
    }

    if (btn) btn.addEventListener('click', async () => {
        btn.disabled = true;
        outSig.textContent = 'Connecting to wallet…';
        try {
            const { key, api } = await pickWallet();
            outSig.textContent = `Connected: ${key}. Fetching used addresses…`;
            const used = await api.getUsedAddresses();
            const recipient = (used && used[0]) || null;

            outSc.textContent = 'Calling sidecar /mint/prepare …';
            const prep = await fetch('/admin/mint-action.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ id: rowId, action: 'prepare_json', recipient_addr_hex: recipient }),
            }).then(r => r.json());

            outSc.textContent = JSON.stringify(prep, null, 2);

            if (prep.stub) {
                outSig.textContent =
                    `Sidecar returned a Phase 1 stub (no real cbor_hex yet).\n` +
                    `Wallet: ${key}\n` +
                    `Recipient (hex): ${recipient}\n` +
                    `\n` +
                    `Phase 2 will pass cbor_hex to wallet.signTx() + submitTx().`;
            } else if (prep.cbor_hex) {
                outSig.textContent = `Signing tx via ${key}…`;
                const witness = await api.signTx(prep.cbor_hex, true);
                const txHash = await api.submitTx(witness);
                outSig.textContent = `Submitted. tx_hash: ${txHash}`;
                // Record the tx_hash server-side
                await fetch('/admin/mint-action.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ id: rowId, action: 'record_tx', tx_hash: txHash }),
                });
            } else {
                outSig.textContent = 'Unexpected sidecar response:\n' + JSON.stringify(prep, null, 2);
            }
        } catch (e) {
            outSig.textContent = 'ERROR: ' + (e && e.message ? e.message : String(e));
        } finally {
            btn.disabled = false;
        }
    });
})();
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
