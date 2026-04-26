<?php
/**
 * Admin home. High-level overview of the mint pipeline.
 */
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

use RareFolio\Blockfrost\Client as BlockfrostClient;
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
$userCount       = tableExists($pdo, 'qd_users') ? (int)$pdo->query("SELECT COUNT(*) FROM qd_users")->fetchColumn() : null;
$listingCount    = tableExists($pdo, 'qd_listings') ? (int)$pdo->query("SELECT COUNT(*) FROM qd_listings WHERE status='active'")->fetchColumn() : null;
$activityCount   = tableExists($pdo, 'qd_nft_activity') ? (int)$pdo->query("SELECT COUNT(*) FROM qd_nft_activity")->fetchColumn() : null;
$collectionCount = tableExists($pdo, 'qd_collections') ? (int)$pdo->query("SELECT COUNT(*) FROM qd_collections")->fetchColumn() : null;
$pendingOrders   = tableExists($pdo, 'qd_orders') ? (int)$pdo->query("SELECT COUNT(*) FROM qd_orders WHERE status IN ('pending','submitted')")->fetchColumn() : null;

$runtimeNetwork = (string) (\RareFolio\Config::get('BLOCKFROST_NETWORK', 'preprod'));
$networkDiagnostics = [];
$networkDiagTotals = ['ok' => 0, 'warn' => 0, 'error' => 0];
$networkDiagError = null;

if (tableExists($pdo, 'qd_collections') && tableExists($pdo, 'qd_tokens')) {
    try {
        $collections = $pdo->query("
            SELECT id, slug, name, network, edition_size, primary_minted_count, all_primary_minted
            FROM qd_collections
            ORDER BY created_at DESC
        ")->fetchAll();

        $mintedCountStmt = $pdo->prepare("
            SELECT COUNT(*) AS minted_tokens
            FROM qd_tokens
            WHERE collection_slug = ?
              AND mint_tx_hash IS NOT NULL
              AND mint_tx_hash <> ''
        ");
        $mintedSampleStmt = $pdo->prepare("
            SELECT mint_tx_hash
            FROM qd_tokens
            WHERE collection_slug = ?
              AND mint_tx_hash IS NOT NULL
              AND mint_tx_hash <> ''
            ORDER BY minted_at DESC, id DESC
            LIMIT 3
        ");

        $bfClients = [];
        $getClient = static function (string $network) use (&$bfClients): BlockfrostClient {
            if (!isset($bfClients[$network])) {
                $bfClients[$network] = new BlockfrostClient($network);
            }
            return $bfClients[$network];
        };

        $txExistsOn = static function (?BlockfrostClient $client, string $txHash, ?string &$error): bool {
            if ($client === null) {
                return false;
            }
            try {
                return $client->tx($txHash) !== null;
            } catch (Throwable $e) {
                if ($error === null) {
                    $error = $e->getMessage();
                }
                return false;
            }
        };

        $severityRank = ['ok' => 0, 'warn' => 1, 'error' => 2];
        $promoteSeverity = static function (string $current, string $target) use ($severityRank): string {
            return ($severityRank[$target] > $severityRank[$current]) ? $target : $current;
        };

        foreach ($collections as $col) {
            $collectionId = (int) $col['id'];
            $slug = (string) $col['slug'];
            $declaredNetwork = (string) $col['network'];
            $editionSize = (int) $col['edition_size'];
            $storedPrimaryMinted = (int) $col['primary_minted_count'];
            $storedAllPrimaryMinted = (int) $col['all_primary_minted'];

            $mintedCountStmt->execute([$slug]);
            $mintedTokens = (int) $mintedCountStmt->fetchColumn();

            $mintedSampleStmt->execute([$slug]);
            $sampleTxHashes = array_map('strval', $mintedSampleStmt->fetchAll(PDO::FETCH_COLUMN));
            $sampleCount = count($sampleTxHashes);

            $expectedAllPrimaryMinted = ($editionSize > 0 && $mintedTokens >= $editionSize) ? 1 : 0;
            $counterDrift = ($storedPrimaryMinted !== $mintedTokens)
                || ($storedAllPrimaryMinted !== $expectedAllPrimaryMinted);

            $declaredClient = null;
            $runtimeClient = null;
            $declaredClientError = null;
            $runtimeClientError = null;
            try {
                $declaredClient = $getClient($declaredNetwork);
            } catch (Throwable $e) {
                $declaredClientError = $e->getMessage();
            }
            if ($runtimeNetwork !== $declaredNetwork) {
                try {
                    $runtimeClient = $getClient($runtimeNetwork);
                } catch (Throwable $e) {
                    $runtimeClientError = $e->getMessage();
                }
            }

            $declaredHits = 0;
            $runtimeHits = 0;
            $declaredProbeError = null;
            $runtimeProbeError = null;
            foreach ($sampleTxHashes as $txHash) {
                if ($txExistsOn($declaredClient, $txHash, $declaredProbeError)) {
                    $declaredHits++;
                }
                if ($runtimeClient !== null && $txExistsOn($runtimeClient, $txHash, $runtimeProbeError)) {
                    $runtimeHits++;
                }
            }

            $severity = 'ok';
            $reasons = [];

            if ($counterDrift) {
                $severity = $promoteSeverity($severity, 'warn');
                $reasons[] = 'stored counters differ from minted token truth';
            }
            if ($runtimeNetwork !== $declaredNetwork) {
                $severity = $promoteSeverity($severity, 'warn');
                $reasons[] = "declared network ($declaredNetwork) differs from runtime env ($runtimeNetwork)";
            }

            if ($sampleCount > 0) {
                if ($declaredClientError !== null) {
                    $severity = $promoteSeverity($severity, 'error');
                    $reasons[] = "cannot initialize Blockfrost client for declared network: $declaredClientError";
                } elseif ($declaredProbeError !== null) {
                    $severity = $promoteSeverity($severity, 'error');
                    $reasons[] = "declared-network tx verification failed: $declaredProbeError";
                } elseif ($declaredHits === 0) {
                    $severity = $promoteSeverity($severity, 'error');
                    $reasons[] = "no sample mint tx hashes resolve on declared network ($declaredNetwork)";
                } elseif ($declaredHits < $sampleCount) {
                    $severity = $promoteSeverity($severity, 'warn');
                    $reasons[] = "$declaredHits/$sampleCount sample tx hashes resolve on declared network";
                }

                if ($runtimeClient !== null) {
                    if ($runtimeClientError !== null || $runtimeProbeError !== null) {
                        $severity = $promoteSeverity($severity, 'warn');
                        $reasons[] = 'runtime-network cross-check is not fully verifiable';
                    } elseif ($runtimeHits > 0 && $declaredHits === 0) {
                        $severity = $promoteSeverity($severity, 'error');
                        $reasons[] = "sample tx hashes resolve on runtime network ($runtimeNetwork), not declared network";
                    } elseif ($runtimeHits > 0 && $declaredHits > 0) {
                        $severity = $promoteSeverity($severity, 'warn');
                        $reasons[] = 'sample tx hashes appear on both declared and runtime checks';
                    }
                }
            }

            $networkDiagTotals[$severity]++;
            $networkDiagnostics[] = [
                'collection_id' => $collectionId,
                'collection_name' => (string) $col['name'],
                'collection_slug' => $slug,
                'declared_network' => $declaredNetwork,
                'runtime_network' => $runtimeNetwork,
                'minted_tokens' => $mintedTokens,
                'edition_size' => $editionSize,
                'stored_primary_minted' => $storedPrimaryMinted,
                'stored_all_primary' => $storedAllPrimaryMinted,
                'expected_all_primary' => $expectedAllPrimaryMinted,
                'counter_drift' => $counterDrift,
                'sample_count' => $sampleCount,
                'declared_hits' => $declaredHits,
                'runtime_hits' => $runtimeHits,
                'severity' => $severity,
                'reasons' => $reasons,
            ];
        }
    } catch (Throwable $e) {
        $networkDiagError = $e->getMessage();
    }
}

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
            draft <?= (int)($counts['draft'] ?? 0) ?> ·
            ready <?= (int)($counts['ready'] ?? 0) ?> ·
            signed <?= (int)($counts['signed'] ?? 0) ?> ·
            submitted <?= (int)($counts['submitted'] ?? 0) ?> ·
            confirmed <?= (int)($counts['confirmed'] ?? 0) ?> ·
            failed <?= (int)($counts['failed'] ?? 0) ?>
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
    <?php if ($collectionCount !== null): ?>
    <div class="rf-code" style="white-space:normal">
        <div class="rf-mono">COLLECTIONS</div>
        <div style="font-size:1.8rem; font-family: 'Cormorant Garamond', Georgia, serif;"><?= $collectionCount ?></div>
        <small class="rf-mono"><a href="/admin/collections.php" style="color:inherit">qd_collections</a></small>
    </div>
    <?php endif; ?>
    <?php if ($pendingOrders !== null): ?>
    <div class="rf-code" style="white-space:normal">
        <div class="rf-mono">PENDING ORDERS</div>
        <div style="font-size:1.8rem; font-family: 'Cormorant Garamond', Georgia, serif;
                    color: <?= $pendingOrders > 0 ? 'var(--rf-warn)' : 'inherit' ?>"><?= $pendingOrders ?></div>
        <small class="rf-mono"><a href="/admin/orders.php" style="color:inherit">qd_orders</a></small>
    </div>
    <?php endif; ?>
</div>

<h2>Network consistency</h2>
<?php if ($networkDiagError !== null): ?>
    <div class="rf-alert rf-alert-error">
        Consistency check failed: <?= h($networkDiagError) ?>
    </div>
<?php elseif (empty($networkDiagnostics)): ?>
    <div class="rf-alert rf-alert-warn">
        No collections found to evaluate.
    </div>
<?php else: ?>
    <div class="rf-toolbar">
        <span class="rf-mono">runtime BLOCKFROST_NETWORK = <?= h($runtimeNetwork) ?></span>
        <span class="rf-mono">ok <?= (int) $networkDiagTotals['ok'] ?></span>
        <span class="rf-mono" style="color:var(--rf-warn)">warn <?= (int) $networkDiagTotals['warn'] ?></span>
        <span class="rf-mono" style="color:var(--rf-error)">error <?= (int) $networkDiagTotals['error'] ?></span>
    </div>
    <?php if ((int) $networkDiagTotals['error'] > 0): ?>
        <div class="rf-alert rf-alert-error">
            One or more collections have hard network drift. Review rows marked <strong>error</strong> before minting.
        </div>
    <?php elseif ((int) $networkDiagTotals['warn'] > 0): ?>
        <div class="rf-alert rf-alert-warn">
            Collections are mostly healthy, but there are warning-level drifts worth reconciling.
        </div>
    <?php else: ?>
        <div class="rf-alert rf-alert-ok">
            All checked collections passed network consistency diagnostics.
        </div>
    <?php endif; ?>

    <table class="rf-table">
        <thead>
            <tr>
                <th>Collection</th>
                <th>Declared / Runtime</th>
                <th>Minted counters</th>
                <th>Tx sample check</th>
                <th>Status</th>
                <th>Details</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($networkDiagnostics as $diag): ?>
            <?php
                $statusColor = $diag['severity'] === 'error'
                    ? 'var(--rf-error)'
                    : ($diag['severity'] === 'warn' ? 'var(--rf-warn)' : 'var(--rf-ok)');
                $txSummary = $diag['sample_count'] > 0
                    ? ($diag['declared_hits'] . '/' . $diag['sample_count'] . ' on declared'
                        . ($diag['declared_network'] !== $diag['runtime_network']
                            ? ' · ' . $diag['runtime_hits'] . '/' . $diag['sample_count'] . ' on runtime'
                            : ''))
                    : 'no minted tx sample';
            ?>
            <tr>
                <td>
                    <a href="/admin/collection-detail.php?id=<?= (int) $diag['collection_id'] ?>">
                        <strong><?= h($diag['collection_name']) ?></strong>
                    </a>
                    <br><small class="rf-mono"><?= h($diag['collection_slug']) ?></small>
                </td>
                <td class="rf-mono"><?= h($diag['declared_network']) ?> / <?= h($diag['runtime_network']) ?></td>
                <td class="rf-mono">
                    stored <?= (int) $diag['stored_primary_minted'] ?>/<?= (int) $diag['edition_size'] ?><br>
                    derived <?= (int) $diag['minted_tokens'] ?>/<?= (int) $diag['edition_size'] ?><br>
                    all_primary_minted stored <?= (int) $diag['stored_all_primary'] ?>, expected <?= (int) $diag['expected_all_primary'] ?>
                </td>
                <td class="rf-mono"><?= h((string) $txSummary) ?></td>
                <td>
                    <span class="rf-pill" style="border-color:<?= h($statusColor) ?>;color:<?= h($statusColor) ?>">
                        <?= h((string) $diag['severity']) ?>
                    </span>
                </td>
                <td>
                    <?php if (!empty($diag['reasons'])): ?>
                        <ul style="margin:0;padding-left:1rem;">
                            <?php foreach ($diag['reasons'] as $reason): ?>
                                <li class="rf-mono"><?= h((string) $reason) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    <?php else: ?>
                        <span class="rf-mono" style="color:var(--rf-ok)">no drift detected</span>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>

<h2>Quick actions</h2>
<div class="rf-toolbar">
    <a class="rf-btn" href="/admin/mint-new.php">+ New mint</a>
    <a class="rf-btn rf-btn-ghost" href="/admin/mint.php">Mint queue</a>
    <a class="rf-btn rf-btn-ghost" href="/admin/asset-lookup.php">Asset lookup</a>
    <?php if ($activityCount !== null): ?>
    <a class="rf-btn rf-btn-ghost" href="/admin/activity.php">Provenance log</a>
    <?php endif; ?>
    <?php if ($collectionCount !== null): ?>
    <a class="rf-btn rf-btn-ghost" href="/admin/collections.php">Collections</a>
    <?php endif; ?>
    <?php if ($pendingOrders !== null): ?>
    <a class="rf-btn rf-btn-ghost" href="/admin/orders.php"><?= $pendingOrders ?> pending order<?= $pendingOrders !== 1 ? 's' : '' ?></a>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>