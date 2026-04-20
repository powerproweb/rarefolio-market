<?php
/**
 * Provenance log — admin viewer for qd_nft_activity.
 *
 * Shows all events (mint, transfer, sale, gift, list, delist) in reverse
 * chronological order. Filterable by token ID and event type.
 *
 * Gracefully displays a banner if the table has not been migrated yet.
 */
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

// Check table exists
$tableExists = (bool) $pdo->query(
    "SELECT COUNT(*) FROM information_schema.tables
     WHERE table_schema = DATABASE() AND table_name = 'qd_nft_activity'"
)->fetchColumn();

$filterToken = trim((string) ($_GET['token'] ?? ''));
$filterType  = (string) ($_GET['type'] ?? '');
$allowedTypes = ['', 'mint', 'transfer', 'sale', 'gift', 'list', 'delist'];
if (!in_array($filterType, $allowedTypes, true)) $filterType = '';

$limit  = 100;
$rows   = [];
$total  = 0;

if ($tableExists) {
    $where  = '1=1';
    $binds  = [];

    if ($filterToken !== '') {
        $where .= ' AND rarefolio_token_id LIKE :token';
        $binds[':token'] = '%' . $filterToken . '%';
    }
    if ($filterType !== '') {
        $where .= ' AND event_type = :type';
        $binds[':type'] = $filterType;
    }

    $total = (int) $pdo->prepare("SELECT COUNT(*) FROM qd_nft_activity WHERE $where")
                       ->execute($binds) ? $pdo->query("SELECT FOUND_ROWS()")->fetchColumn() : 0;

    // Simple COUNT since FOUND_ROWS needs SQL_CALC_FOUND_ROWS
    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM qd_nft_activity WHERE $where");
    $countStmt->execute($binds);
    $total = (int) $countStmt->fetchColumn();

    $stmt = $pdo->prepare("
        SELECT id, rarefolio_token_id, event_type, from_addr, to_addr,
               sale_amount_lovelace, tx_hash, note, event_at
        FROM qd_nft_activity
        WHERE $where
        ORDER BY event_at DESC
        LIMIT $limit
    ");
    $stmt->execute($binds);
    $rows = $stmt->fetchAll();
}

$pageTitle = 'Provenance log — RareFolio admin';
require __DIR__ . '/includes/header.php';
?>

<h1>Provenance log</h1>
<p class="rf-mono">qd_nft_activity — append-only event history for every CNFT.</p>

<?php if (!$tableExists): ?>
    <div class="rf-alert rf-alert-warn">
        The <code>qd_nft_activity</code> table does not exist yet.
        Run <code>php db/migrate.php</code> to apply migration 012.
    </div>
<?php else: ?>

<form method="get" class="rf-form" style="max-width:900px; margin-bottom:1.5rem">
    <div style="display:grid; grid-template-columns: 1fr 180px auto; gap:0.75rem;">
        <input type="text" name="token" value="<?= h($filterToken) ?>"
               placeholder="Filter by token ID (partial match)">
        <select name="type">
            <?php foreach ($allowedTypes as $t): ?>
                <option value="<?= h($t) ?>" <?= $filterType === $t ? 'selected' : '' ?>>
                    <?= $t === '' ? 'all events' : $t ?>
                </option>
            <?php endforeach; ?>
        </select>
        <button class="rf-btn" type="submit">Filter</button>
    </div>
</form>

<p class="rf-mono" style="font-size:0.8rem;">
    Showing <?= count($rows) ?> of <?= $total ?> events (latest <?= $limit ?> max).
</p>

<?php if ($rows === []): ?>
    <div class="rf-alert">No activity recorded yet.</div>
<?php else: ?>
    <table class="rf-table">
        <thead>
            <tr>
                <th>When</th>
                <th>Token ID</th>
                <th>Event</th>
                <th>From</th>
                <th>To</th>
                <th>Amount</th>
                <th>Tx</th>
                <th>Note</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
            <tr>
                <td class="rf-mono" style="white-space:nowrap"><?= h($r['event_at']) ?></td>
                <td class="rf-mono">
                    <a href="/admin/asset-lookup.php?mode=asset&q=<?= h($r['rarefolio_token_id']) ?>">
                        <?= h($r['rarefolio_token_id']) ?>
                    </a>
                </td>
                <td>
                    <span class="rf-pill rf-pill-<?= h($r['event_type']) ?>"><?= h($r['event_type']) ?></span>
                </td>
                <td class="rf-mono" style="font-size:0.75rem">
                    <?php if ($r['from_addr']): ?>
                        <?= h(substr($r['from_addr'], 0, 10)) ?>&hellip;
                    <?php else: ?>&mdash;<?php endif; ?>
                </td>
                <td class="rf-mono" style="font-size:0.75rem">
                    <?php if ($r['to_addr']): ?>
                        <?= h(substr($r['to_addr'], 0, 10)) ?>&hellip;
                    <?php else: ?>&mdash;<?php endif; ?>
                </td>
                <td class="rf-mono">
                    <?php if ($r['sale_amount_lovelace']): ?>
                        <?= number_format((int)$r['sale_amount_lovelace'] / 1_000_000, 2) ?> ADA
                    <?php else: ?>&mdash;<?php endif; ?>
                </td>
                <td class="rf-mono" style="font-size:0.75rem">
                    <?php if ($r['tx_hash']): ?>
                        <?= h(substr($r['tx_hash'], 0, 10)) ?>&hellip;
                    <?php else: ?>&mdash;<?php endif; ?>
                </td>
                <td><?= h($r['note'] ?? '') ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>
<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>
