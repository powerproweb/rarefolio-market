<?php
/**
 * Collections overview — lists all entries in qd_collections.
 * Graceful if the table has not yet been migrated.
 */
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

$tableExists = (bool) $pdo->query(
    "SELECT COUNT(*) FROM information_schema.tables
     WHERE table_schema = DATABASE() AND table_name = 'qd_collections'"
)->fetchColumn();

$collections = [];
if ($tableExists) {
    $collections = $pdo->query("
        SELECT c.*,
            (SELECT COUNT(*) FROM qd_royalty_recipients r WHERE r.collection_id = c.id) AS recipient_count,
            (SELECT COUNT(*) FROM qd_tokens t WHERE t.collection_slug = c.slug AND t.primary_sale_status = 'minted') AS on_chain_count
        FROM qd_collections c
        ORDER BY c.created_at DESC
    ")->fetchAll();
}

$pageTitle = 'Collections — RareFolio admin';
require __DIR__ . '/includes/header.php';
?>

<h1>Collections</h1>

<?php if (!$tableExists): ?>
    <div class="rf-alert rf-alert-warn">
        The <code>qd_collections</code> table does not exist yet.
        Run <code>php db/migrate.php</code> to apply migration 013.
    </div>
<?php else: ?>

<div class="rf-toolbar">
    <a class="rf-btn" href="/admin/collection-new.php">+ New collection</a>
</div>

<?php if (empty($collections)): ?>
    <div class="rf-alert">No collections yet. Click "+ New collection" to create the first one.</div>
<?php else: ?>
    <table class="rf-table">
        <thead>
            <tr>
                <th>Name</th>
                <th>Slug</th>
                <th>Env key</th>
                <th>Policy ID</th>
                <th>Lock</th>
                <th>Progress</th>
                <th>Royalty</th>
                <th>Recipients</th>
                <th>Network</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($collections as $c): ?>
            <tr>
                <td>
                    <a href="/admin/collection-detail.php?id=<?= (int)$c['id'] ?>">
                        <strong><?= h($c['name']) ?></strong>
                    </a>
                </td>
                <td class="rf-mono"><?= h($c['slug']) ?></td>
                <td class="rf-mono"><?= h($c['policy_env_key']) ?></td>
                <td class="rf-mono" style="font-size:0.75rem">
                    <?php if ($c['policy_id']): ?>
                        <?= h(substr($c['policy_id'], 0, 12)) ?>&hellip;
                    <?php else: ?>
                        <span style="color:var(--rf-warn)">not derived</span>
                    <?php endif; ?>
                </td>
                <td>
                    <span class="rf-pill rf-pill-<?= h($c['lock_status']) ?>"><?= h($c['lock_status']) ?></span>
                </td>
                <td class="rf-mono">
                    <?= (int)$c['primary_minted_count'] ?> / <?= (int)$c['edition_size'] ?>
                    <?php if ($c['all_primary_minted']): ?>
                        <span style="color:var(--rf-ok)"> ✓</span>
                    <?php endif; ?>
                </td>
                <td class="rf-mono"><?= number_format((float)$c['royalty_total_pct'], 2) ?>% creator + <?= number_format((float)$c['platform_fee_pct'], 2) ?>% platform</td>
                <td class="rf-mono"><?= (int)$c['recipient_count'] ?></td>
                <td><span class="rf-pill"><?= h($c['network']) ?></span></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>
<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>
