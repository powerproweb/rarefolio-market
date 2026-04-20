<?php
/**
 * Orders — admin view for pending primary sale orders.
 *
 * Actions:
 *   verify   — check the payment tx on Blockfrost
 *   settle   — mark order settled + update qd_tokens.primary_sale_status
 *   reject   — mark order failed (bad payment / wrong amount)
 */
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

use RareFolio\Blockfrost\Client as BlockfrostClient;
use RareFolio\Auth;

// -----------------------------------------------------------------------
// Table existence check
// -----------------------------------------------------------------------
$tableExists = (bool) $pdo->query(
    "SELECT COUNT(*) FROM information_schema.tables
     WHERE table_schema = DATABASE() AND table_name = 'qd_orders'"
)->fetchColumn();

// -----------------------------------------------------------------------
// Handle actions
// -----------------------------------------------------------------------
$flash = $_GET['flash'] ?? null;
$flashKind = $_GET['kind'] ?? 'ok';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $tableExists) {
    $action  = (string)($_POST['action'] ?? '');
    $orderId = (int)($_POST['order_id'] ?? 0);

    if ($orderId > 0) {
        $oStmt = $pdo->prepare('SELECT * FROM qd_orders WHERE id = ?');
        $oStmt->execute([$orderId]);
        $order = $oStmt->fetch();

        if ($order) {
            if ($action === 'verify') {
                $bf = new BlockfrostClient();
                $tx = $bf->tx($order['order_tx_hash'] ?? '');
                if ($tx) {
                    $flash = 'Payment verified on-chain. Tx confirmed in block ' . ($tx['block_height'] ?? '?');
                    $pdo->prepare("UPDATE qd_orders SET block_height=?, updated_at=NOW() WHERE id=?")
                        ->execute([$tx['block_height'] ?? null, $orderId]);
                } else {
                    $flash = 'Tx not yet confirmed — try again shortly.';
                    $flashKind = 'warn';
                }

            } elseif ($action === 'settle') {
                $pdo->prepare("UPDATE qd_orders SET status='settled', settled_at=NOW(), updated_at=NOW() WHERE id=?")->execute([$orderId]);
                // Mark token as sold
                $pdo->prepare("UPDATE qd_tokens SET primary_sale_status='sold', updated_at=NOW() WHERE rarefolio_token_id=?")
                    ->execute([$order['rarefolio_token_id']]);
                // Append to activity log (best-effort)
                try {
                    $nftId = $pdo->query("SELECT id FROM qd_tokens WHERE rarefolio_token_id='" . $pdo->quote($order['rarefolio_token_id']) . "' LIMIT 1")->fetchColumn();
                    if ($nftId) {
                        $pdo->prepare("INSERT INTO qd_nft_activity (nft_id,rarefolio_token_id,event_type,to_addr,tx_hash,sale_amount_lovelace,note,event_at) VALUES (?,?,'sale',?,?,?,?,NOW())")
                            ->execute([$nftId, $order['rarefolio_token_id'], $order['buyer_addr'], $order['order_tx_hash'], $order['sale_amount_lovelace'], 'Primary sale via RareFolio buy page']);
                    }
                } catch (Throwable) {}
                $flash = 'Order #' . $orderId . ' settled. Token marked as sold.';

            } elseif ($action === 'reject') {
                $msg = (string)($_POST['reason'] ?? 'Rejected by admin.');
                $pdo->prepare("UPDATE qd_orders SET status='failed', failure_reason=?, updated_at=NOW() WHERE id=?")->execute([$msg, $orderId]);
                $flash = 'Order #' . $orderId . ' rejected.';
                $flashKind = 'warn';
            }
        }
    }
    header('Location: /admin/orders.php?flash=' . urlencode($flash) . '&kind=' . $flashKind);
    exit;
}

// -----------------------------------------------------------------------
// Load orders
// -----------------------------------------------------------------------
$filter  = $_GET['status'] ?? 'active';
$orders  = [];
if ($tableExists) {
    $where = $filter === 'active'
        ? "status IN ('pending','submitted')"
        : ($filter === 'all' ? '1=1' : 'status = ' . $pdo->quote($filter));
    $orders = $pdo->query("SELECT * FROM qd_orders WHERE $where ORDER BY created_at DESC LIMIT 100")->fetchAll();
}

$pageTitle = 'Orders — RareFolio admin';
require __DIR__ . '/includes/header.php';
?>

<h1>Orders</h1>

<?php if ($flash): ?>
    <div class="rf-alert rf-alert-<?= h($flashKind) ?>"><?= h($flash) ?></div>
<?php endif; ?>

<?php if (!$tableExists): ?>
    <div class="rf-alert rf-alert-warn">
        The <code>qd_orders</code> table does not exist. Run <code>php db/migrate.php</code>.
    </div>
<?php else: ?>

<div class="rf-toolbar">
    <?php foreach (['active'=>'Pending', 'settled'=>'Settled', 'failed'=>'Rejected', 'all'=>'All'] as $f => $lbl): ?>
        <a href="?status=<?= h($f) ?>" class="rf-pill <?= $filter === $f ? 'rf-pill-ready' : '' ?>"><?= $lbl ?></a>
    <?php endforeach; ?>
    <div class="rf-spacer"></div>
    <span class="rf-mono" style="font-size:.85rem"><?= count($orders) ?> orders</span>
</div>

<?php if (empty($orders)): ?>
    <div class="rf-alert">No orders to show.</div>
<?php else: ?>
    <table class="rf-table">
        <thead>
            <tr>
                <th>#</th><th>Token</th><th>Amount</th><th>Status</th>
                <th>Buyer</th><th>Tx hash</th><th>When</th><th>Actions</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($orders as $o): ?>
            <tr>
                <td class="rf-mono"><?= (int)$o['id'] ?></td>
                <td class="rf-mono">
                    <a href="/admin/mint-new.php?from=<?= h($o['rarefolio_token_id']) ?>" title="Open mint form pre-filled with buyer address">
                        <?= h($o['rarefolio_token_id']) ?>
                    </a>
                </td>
                <td class="rf-mono"><?= number_format((int)$o['sale_amount_lovelace'] / 1_000_000, 2) ?> ₳</td>
                <td><span class="rf-pill rf-pill-<?= h($o['status']) ?>"><?= h($o['status']) ?></span></td>
                <td class="rf-mono" style="font-size:.75rem"><?= h(substr($o['buyer_addr'] ?? '', 0, 16)) ?>…</td>
                <td class="rf-mono" style="font-size:.75rem">
                    <?php if ($o['order_tx_hash']): ?>
                        <a href="https://cardanoscan.io/transaction/<?= h($o['order_tx_hash']) ?>" target="_blank" rel="noopener">
                            <?= h(substr($o['order_tx_hash'], 0, 12)) ?>…
                        </a>
                    <?php else: ?>—<?php endif; ?>
                </td>
                <td class="rf-mono" style="font-size:.8rem"><?= h($o['created_at']) ?></td>
                <td>
                    <?php if (in_array($o['status'], ['pending','submitted'], true)): ?>
                        <form method="post" style="display:inline">
                            <input type="hidden" name="order_id" value="<?= (int)$o['id'] ?>">
                            <input type="hidden" name="action" value="verify">
                            <button class="rf-btn rf-btn-ghost" type="submit" style="font-size:.8rem">✓ Verify</button>
                        </form>
                        <form method="post" style="display:inline" onsubmit="return confirm('Mark as settled? This marks the token as SOLD.')">
                            <input type="hidden" name="order_id" value="<?= (int)$o['id'] ?>">
                            <input type="hidden" name="action" value="settle">
                            <button class="rf-btn" type="submit" style="font-size:.8rem">Settle</button>
                        </form>
                        <form method="post" style="display:inline" onsubmit="return confirm('Reject this order?')">
                            <input type="hidden" name="order_id" value="<?= (int)$o['id'] ?>">
                            <input type="hidden" name="action" value="reject">
                            <input type="hidden" name="reason" value="Payment not verified.">
                            <button class="rf-btn rf-btn-ghost" type="submit" style="font-size:.8rem;color:var(--rf-error)">Reject</button>
                        </form>
                        <a href="/admin/mint-new.php?from=<?= h($o['rarefolio_token_id']) ?>"
                           class="rf-btn rf-btn-ghost" style="font-size:.8rem"
                           title="Open mint form — enter buyer addr as recipient to mint NFT to buyer">
                            Mint →
                        </a>
                    <?php else: ?>
                        <span class="rf-mono" style="font-size:.75rem;color:var(--rf-muted)"><?= h($o['status']) ?></span>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <div class="rf-alert" style="margin-top:1.5rem;font-size:.85rem;">
        <strong>Mint to buyer:</strong> Click the "Mint →" button next to an order to open the mint form
        pre-filled with that token's metadata. Enter the buyer's wallet address as the recipient, then
        build &amp; sign the mint transaction.
    </div>
<?php endif; ?>

<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>
