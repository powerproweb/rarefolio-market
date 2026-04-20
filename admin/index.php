<?php
/**
 * Admin home. High-level overview of the mint pipeline.
 */
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

use RareFolio\Sidecar\Client as SidecarClient;

$counts = $pdo->query(
    "SELECT status, COUNT(*) AS c FROM qd_mint_queue GROUP BY status"
)->fetchAll(PDO::FETCH_KEY_PAIR);

$tokenCount        = (int) $pdo->query("SELECT COUNT(*) FROM qd_tokens")->fetchColumn();
$presaleCount      = (int) $pdo->query("SELECT COUNT(*) FROM qd_presales")->fetchColumn();
$unclaimedPresales = (int) $pdo->query("SELECT COUNT(*) FROM qd_presales WHERE claim_status = 'unclaimed'")->fetchColumn();

// Phase 2 tables — gracefully absent before migrations 008–012 are applied
function tableExists(PDO $pdo, string $table): bool {
    return (bool) $pdo->query("SELECT COUNT(*) FROM information_schema.tables
        WHERE table_schema = DATABASE() AND table_name = '$table'")->fetchColumn();
}
$userCount      = tableExists($pdo, 'qd_users')     ? (int)$pdo->query("SELECT COUNT(*) FROM qd_users")->fetchColumn()     : null;
$listingCount   = tableExists($pdo, 'qd_listings')  ? (int)$pdo->query("SELECT COUNT(*) FROM qd_listings WHERE status='active'")->fetchColumn() : null;
$activityCount  = tableExists($pdo, 'qd_nft_activity') ? (int)$pdo->query("SELECT COUNT(*) FROM qd_nft_activity")->fetchColumn() : null;

$sidecar = new SidecarClient();
$sidecarAlive = $sidecar->health();

$pageTitle = 'Admin overview — RareFolio';
require __DIR__ . '/includes/header.php';
?>

<h1>Overview</h1>

<div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(200px,1fr)); gap:1rem; margin-bottom:2rem;">
    <div class="rf-code" style="white-space:normal">
        <div class="rf-mono">MINT QUEUE</div>
        <div style="font-size:1.8rem; font-family: 'Cormorant Garamond', Georgia, serif;">
            <?= (int) array_sum($counts) ?>
        </div>
        <small class="rf-mono">
            draft <?= (int)($counts['draft']     ?? 0) ?> ·
            ready <?= (int)($counts['ready']     ?? 0) ?> ·
            signed <?= (int)($counts['signed']   ?? 0) ?> ·
            submitted <?= (int)($counts['submitted'] ?? 0) ?> ·
            confirmed <?= (int)($counts['confirmed'] ?? 0) ?> ·
            failed <?= (int)($counts['failed']   ?? 0) ?>
        </small>
    </div>
    <div class="rf-code" style="white-space:normal">
        <div class="rf-mono">qd_tokens</div>
        <div style="font-size:1.8rem; font-family: 'Cormorant Garamond', Georgia, serif;">
            <?= $tokenCount ?>
        </div>
        <small class="rf-mono">indexed on-chain assets</small>
    </div>
    <div class="rf-code" style="white-space:normal">
        <div class="rf-mono">qd_presales</div>
        <div style="font-size:1.8rem; font-family: 'Cormorant Garamond', Georgia, serif;">
            <?= $presaleCount ?>
        </div>
        <small class="rf-mono"><?= $unclaimedPresales ?> unclaimed</small>
    </div>
    <div class="rf-code" style="white-space:normal">
        <div class="rf-mono">SIDECAR</div>
        <div style="font-size:1.8rem; font-family: 'Cormorant Garamond', Georgia, serif;
                    color: <?= $sidecarAlive ? 'var(--rf-ok)' : 'var(--rf-error)' ?>">
            <?= $sidecarAlive ? 'online' : 'offline' ?>
        </div>
        <small class="rf-mono"><?= h((string) (\RareFolio\Config::get('SIDECAR_BASE_URL', 'http://localhost:4000'))) ?></small>
    </div>
    <?php if ($userCount !== null): ?>
    <div class="rf-code" style="white-space:normal">
        <div class="rf-mono">qd_users</div>
        <div style="font-size:1.8rem; font-family: 'Cormorant Garamond', Georgia, serif;"><?= $userCount ?></div>
        <small class="rf-mono">registered collectors</small>
    </div>
    <?php endif; ?>
    <?php if ($listingCount !== null): ?>
    <div class="rf-code" style="white-space:normal">
        <div class="rf-mono">ACTIVE LISTINGS</div>
        <div style="font-size:1.8rem; font-family: 'Cormorant Garamond', Georgia, serif;"><?= $listingCount ?></div>
        <small class="rf-mono">via qd_listings</small>
    </div>
    <?php endif; ?>
    <?php if ($activityCount !== null): ?>
    <div class="rf-code" style="white-space:normal">
        <div class="rf-mono">PROVENANCE LOG</div>
        <div style="font-size:1.8rem; font-family: 'Cormorant Garamond', Georgia, serif;"><?= $activityCount ?></div>
        <small class="rf-mono"><a href="/admin/activity.php" style="color:inherit">qd_nft_activity</a></small>
    </div>
    <?php endif; ?>
</div>

<h2>Quick actions</h2>
<div class="rf-toolbar">
    <a class="rf-btn" href="/admin/mint-new.php">+ New mint</a>
    <a class="rf-btn rf-btn-ghost" href="/admin/mint.php">Mint queue</a>
    <a class="rf-btn rf-btn-ghost" href="/admin/asset-lookup.php">Asset lookup</a>
    <?php if ($activityCount !== null): ?>
    <a class="rf-btn rf-btn-ghost" href="/admin/activity.php">Provenance log</a>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
