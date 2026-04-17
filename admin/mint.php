<?php
/**
 * Mint queue dashboard.
 * Lists every row in qd_mint_queue with status + quick links.
 */
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

use RareFolio\Sidecar\Client as SidecarClient;

$filter = $_GET['status'] ?? '';
$allowedFilters = ['', 'draft', 'ready', 'signed', 'submitted', 'confirmed', 'failed'];
if (!in_array($filter, $allowedFilters, true)) {
    $filter = '';
}

$sql = 'SELECT * FROM qd_mint_queue';
$params = [];
if ($filter !== '') {
    $sql .= ' WHERE status = :status';
    $params['status'] = $filter;
}
$sql .= ' ORDER BY created_at DESC LIMIT 200';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

// Count by status for the toolbar
$counts = $pdo->query(
    "SELECT status, COUNT(*) AS c FROM qd_mint_queue GROUP BY status"
)->fetchAll(PDO::FETCH_KEY_PAIR);

$sidecarAlive = (new SidecarClient())->health();

$pageTitle = 'Mint queue — RareFolio admin';
require __DIR__ . '/includes/header.php';
?>

<h1>Mint queue</h1>

<div class="rf-toolbar">
    <?php foreach (['' => 'all', 'draft' => 'draft', 'ready' => 'ready', 'signed' => 'signed',
                    'submitted' => 'submitted', 'confirmed' => 'confirmed', 'failed' => 'failed'] as $f => $label): ?>
        <a href="?status=<?= h($f) ?>"
           class="rf-pill <?= $filter === $f ? 'rf-pill-ready' : '' ?>">
            <?= h($label) ?><?php if ($f !== '' && isset($counts[$f])): ?> (<?= (int)$counts[$f] ?>)<?php endif; ?>
        </a>
    <?php endforeach; ?>
    <div class="rf-spacer"></div>
    <span class="rf-mono">
        sidecar: <?= $sidecarAlive ? '<span style="color:var(--rf-ok)">online</span>'
                                   : '<span style="color:var(--rf-error)">offline</span>' ?>
    </span>
    <a class="rf-btn" href="/admin/mint-new.php">+ New mint</a>
</div>

<?php if ($rows === []): ?>
    <div class="rf-alert">No mints in the queue yet. Click “+ New mint” to build the first one.</div>
<?php else: ?>
    <table class="rf-table">
        <thead>
            <tr>
                <th>Token ID</th>
                <th>Title</th>
                <th>Collection</th>
                <th>Edition</th>
                <th>Status</th>
                <th>Tx</th>
                <th>Updated</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
            <tr>
                <td class="rf-mono">
                    <a href="/admin/mint-detail.php?id=<?= (int)$r['id'] ?>"><?= h($r['rarefolio_token_id']) ?></a>
                </td>
                <td>
                    <a href="/admin/mint-detail.php?id=<?= (int)$r['id'] ?>"><strong><?= h($r['title']) ?></strong></a>
                    <?php if (!empty($r['character_name'])): ?>
                        <br><small class="rf-mono"><?= h($r['character_name']) ?></small>
                    <?php endif; ?>
                </td>
                <td><?= h($r['collection_slug']) ?></td>
                <td><?= h($r['edition'] ?? '') ?></td>
                <td>
                    <span class="rf-pill rf-pill-<?= h($r['status']) ?>"><?= h($r['status']) ?></span>
                    <?php if (!empty($r['error_message'])): ?>
                        <br><small style="color:var(--rf-error)"><?= h(substr($r['error_message'], 0, 80)) ?></small>
                    <?php endif; ?>
                </td>
                <td class="rf-mono">
                    <?php if (!empty($r['tx_hash'])): ?>
                        <?= h(substr($r['tx_hash'], 0, 10)) ?>&hellip;
                    <?php else: ?>
                        &mdash;
                    <?php endif; ?>
                </td>
                <td class="rf-mono"><?= h($r['updated_at']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>
