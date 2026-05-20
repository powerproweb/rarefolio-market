<?php
/**
 * Wallets view for admin operations.
 *
 * Shows:
 * - Policy wallet details (policy id + policy address)
 * - Split wallet balance
 * - Companion treasury wallet balance
 * - Optional companion unit quantity in treasury
 */
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

use RareFolio\Sidecar\Client as SidecarClient;

function rf_table_exists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM information_schema.tables
         WHERE table_schema = DATABASE() AND table_name = ?"
    );
    $stmt->execute([$table]);
    return (bool) $stmt->fetchColumn();
}

function rf_valid_env_key(string $v): bool
{
    return (bool) preg_match('/^[A-Z0-9_]{1,64}$/', $v);
}
function rf_cip25_extract_image(mixed $cip25): ?string
{
    $extract = static function (mixed $value): ?string {
        if (is_string($value)) {
            $v = trim($value);
            return $v !== '' ? $v : null;
        }
        if (is_array($value)) {
            $parts = [];
            foreach ($value as $item) {
                if (is_string($item) && $item !== '') {
                    $parts[] = $item;
                }
            }
            $joined = trim(implode('', $parts));
            return $joined !== '' ? $joined : null;
        }
        return null;
    };

    if (!is_array($cip25)) return null;

    $rootImage = $extract($cip25['image'] ?? null);
    if ($rootImage !== null) return $rootImage;

    if (isset($cip25['721']) && is_array($cip25['721'])) {
        foreach ($cip25['721'] as $policyNode) {
            if (!is_array($policyNode)) continue;
            foreach ($policyNode as $assetNode) {
                if (!is_array($assetNode)) continue;
                $nestedImage = $extract($assetNode['image'] ?? null);
                if ($nestedImage !== null) return $nestedImage;
            }
        }
    }

    foreach ($cip25 as $policyNode) {
        if (!is_array($policyNode)) continue;
        foreach ($policyNode as $assetNode) {
            if (!is_array($assetNode)) continue;
            $nestedImage = $extract($assetNode['image'] ?? null);
            if ($nestedImage !== null) return $nestedImage;
        }
    }

    return null;
}
function rf_normalize_image_url(?string $raw): ?string
{
    if ($raw === null) return null;
    $v = trim($raw);
    if ($v === '') return null;
    if (stripos($v, 'ipfs://') === 0) {
        $rest = ltrim(substr($v, 7), '/');
        return $rest !== '' ? ('https://gateway.pinata.cloud/ipfs/' . $rest) : null;
    }
    if ($v[0] === '/') {
        return 'https://rarefolio.io' . $v;
    }
    if (filter_var($v, FILTER_VALIDATE_URL) && preg_match('#^https?://#i', $v)) {
        return $v;
    }
    return null;
}

$collections = [];
$collectionsTableExists = rf_table_exists($pdo, 'qd_collections');
if ($collectionsTableExists) {
    $collections = $pdo->query(
        "SELECT id, slug, name, network, policy_env_key, split_wallet_env_key, policy_id, policy_addr, split_wallet_addr, lock_slot
         FROM qd_collections
         ORDER BY created_at DESC"
    )->fetchAll();
}

$selectedCollectionId = (int) ($_GET['collection_id'] ?? 0);
if ($selectedCollectionId <= 0 && !empty($collections)) {
    $selectedCollectionId = (int) $collections[0]['id'];
}

$selectedCollection = null;
foreach ($collections as $col) {
    if ((int) $col['id'] === $selectedCollectionId) {
        $selectedCollection = $col;
        break;
    }
}

$defaultPolicyEnvKey = strtoupper(trim((string) ($selectedCollection['policy_env_key'] ?? '')));
$defaultSplitEnvKey = strtoupper(trim((string) ($selectedCollection['split_wallet_env_key'] ?? '')));
if ($defaultSplitEnvKey === '') {
    $defaultSplitEnvKey = $defaultPolicyEnvKey;
}
$defaultTreasuryEnvKey = $defaultSplitEnvKey !== '' ? $defaultSplitEnvKey : $defaultPolicyEnvKey;

$policyEnvKey = strtoupper(trim((string) ($_GET['policy_env_key'] ?? $defaultPolicyEnvKey)));
$splitEnvKey = strtoupper(trim((string) ($_GET['split_env_key'] ?? $defaultSplitEnvKey)));
$treasuryEnvKey = strtoupper(trim((string) ($_GET['treasury_env_key'] ?? $defaultTreasuryEnvKey)));
$companionUnit = strtolower(trim((string) ($_GET['companion_unit'] ?? '')));

$inputErrors = [];
if ($policyEnvKey !== '' && !rf_valid_env_key($policyEnvKey)) {
    $inputErrors[] = 'Policy env key is invalid.';
    $policyEnvKey = '';
}
if ($splitEnvKey !== '' && !rf_valid_env_key($splitEnvKey)) {
    $inputErrors[] = 'Split env key is invalid.';
    $splitEnvKey = '';
}
if ($treasuryEnvKey !== '' && !rf_valid_env_key($treasuryEnvKey)) {
    $inputErrors[] = 'Treasury env key is invalid.';
    $treasuryEnvKey = '';
}
if ($companionUnit !== '' && !preg_match('/^[0-9a-f]{56,}$/', $companionUnit)) {
    $inputErrors[] = 'Companion unit must be hex policy_id + asset_name_hex.';
    $companionUnit = '';
}

$envKeyOptions = [];
foreach ($collections as $col) {
    $k1 = strtoupper(trim((string) ($col['policy_env_key'] ?? '')));
    $k2 = strtoupper(trim((string) ($col['split_wallet_env_key'] ?? '')));
    if ($k1 !== '') $envKeyOptions[$k1] = true;
    if ($k2 !== '') $envKeyOptions[$k2] = true;
}
ksort($envKeyOptions);
$envKeyOptions = array_keys($envKeyOptions);
$tokensTableExists = rf_table_exists($pdo, 'qd_tokens');
$assetLimit = 250;
$assetCountTotal = 0;
$collectionAssets = [];
$assetsError = null;

if ($selectedCollection && $tokensTableExists) {
    try {
        $slug = (string) $selectedCollection['slug'];
        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM qd_tokens WHERE collection_slug = ?");
        $countStmt->execute([$slug]);
        $assetCountTotal = (int) $countStmt->fetchColumn();

        $listStmt = $pdo->prepare(
            "SELECT rarefolio_token_id, title, character_name, policy_id, asset_name_hex,
                    primary_sale_status, listing_status, current_owner_wallet, mint_tx_hash, cip25_json
             FROM qd_tokens
             WHERE collection_slug = ?
             ORDER BY rarefolio_token_id ASC
             LIMIT 250"
        );
        $listStmt->execute([$slug]);
        $rows = $listStmt->fetchAll();

        foreach ($rows as $row) {
            $cip25 = json_decode((string) ($row['cip25_json'] ?? ''), true);
            $rawImage = rf_cip25_extract_image($cip25);
            $imageUrl = rf_normalize_image_url($rawImage);

            $policyId = strtolower(trim((string) ($row['policy_id'] ?? '')));
            $assetHex = strtolower(trim((string) ($row['asset_name_hex'] ?? '')));
            $unit = (
                preg_match('/^[0-9a-f]{56}$/', $policyId) &&
                preg_match('/^[0-9a-f]{1,128}$/', $assetHex)
            ) ? ($policyId . $assetHex) : '';

            $owner = trim((string) ($row['current_owner_wallet'] ?? ''));
            $ownerShort = $owner;
            if ($ownerShort !== '' && strlen($ownerShort) > 28) {
                $ownerShort = substr($ownerShort, 0, 14) . '…' . substr($ownerShort, -10);
            }

            $collectionAssets[] = [
                'rarefolio_token_id' => (string) ($row['rarefolio_token_id'] ?? ''),
                'title'              => (string) ($row['title'] ?? ''),
                'character_name'     => (string) ($row['character_name'] ?? ''),
                'primary_sale_status'=> (string) ($row['primary_sale_status'] ?? ''),
                'listing_status'     => (string) ($row['listing_status'] ?? ''),
                'mint_tx_hash'       => (string) ($row['mint_tx_hash'] ?? ''),
                'owner_short'        => $ownerShort,
                'unit'               => $unit,
                'image_url'          => $imageUrl,
                'raw_image'          => $rawImage,
            ];
        }
    } catch (Throwable $e) {
        $assetsError = $e->getMessage();
    }
}

$sidecar = new SidecarClient();
$sidecarHealthy = $sidecar->health();

$policyInfo = null;
$policyError = null;
$splitBalance = null;
$splitError = null;
$treasuryBalance = null;
$treasuryError = null;
$treasuryUnitBalance = null;
$treasuryUnitError = null;

if (empty($inputErrors) && $sidecarHealthy) {
    if ($policyEnvKey !== '') {
        try {
            $lockSlot = null;
            if ($selectedCollection && !empty($selectedCollection['lock_slot'])) {
                $lockSlot = (int) $selectedCollection['lock_slot'];
            }
            $policyInfo = $sidecar->getPolicyInfoForKey($policyEnvKey, $lockSlot);
        } catch (Throwable $e) {
            $policyError = $e->getMessage();
        }
    }

    if ($splitEnvKey !== '') {
        try {
            $splitBalance = $sidecar->getSweepBalance($splitEnvKey);
        } catch (Throwable $e) {
            $splitError = $e->getMessage();
        }
    }

    if ($treasuryEnvKey !== '') {
        try {
            $treasuryBalance = $sidecar->getCompanionTreasuryBalance($treasuryEnvKey);
        } catch (Throwable $e) {
            $treasuryError = $e->getMessage();
        }
    }

    if ($treasuryEnvKey !== '' && $companionUnit !== '') {
        try {
            $treasuryUnitBalance = $sidecar->getCompanionTreasuryUnitBalance($treasuryEnvKey, $companionUnit);
        } catch (Throwable $e) {
            $treasuryUnitError = $e->getMessage();
        }
    }
}

$pageTitle = 'Wallets - RareFolio admin';
require __DIR__ . '/includes/header.php';
?>

<h1>Wallets</h1>
<p class="rf-mono">
    View policy, split, and companion treasury wallets from one screen.
    Use env keys from <code>qd_collections</code> and sidecar configuration.
</p>

<?php if (!$collectionsTableExists): ?>
    <div class="rf-alert rf-alert-warn">
        The <code>qd_collections</code> table does not exist yet.
        Run migrations before using the wallet selector.
    </div>
<?php endif; ?>

<?php if (!$sidecarHealthy): ?>
    <div class="rf-alert rf-alert-error">
        Sidecar is offline or not reachable. Wallet data cannot be loaded.
    </div>
<?php endif; ?>

<?php foreach ($inputErrors as $err): ?>
    <div class="rf-alert rf-alert-error"><?= h($err) ?></div>
<?php endforeach; ?>

<form method="get" class="rf-form" style="max-width:980px">
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:0.75rem;">
        <div>
            <label>Collection</label>
            <select name="collection_id">
                <?php if (empty($collections)): ?>
                    <option value="">No collections found</option>
                <?php else: ?>
                    <?php foreach ($collections as $col): ?>
                        <option value="<?= (int) $col['id'] ?>" <?= ((int) $col['id'] === $selectedCollectionId) ? 'selected' : '' ?>>
                            <?= h((string) $col['name']) ?> (<?= h((string) $col['slug']) ?>)
                        </option>
                    <?php endforeach; ?>
                <?php endif; ?>
            </select>
        </div>
        <div>
            <label>Companion unit (optional)</label>
            <input
                type="text"
                name="companion_unit"
                value="<?= h($companionUnit) ?>"
                placeholder="policy_id + asset_name_hex"
            >
        </div>
    </div>

    <datalist id="env-key-options">
        <?php foreach ($envKeyOptions as $key): ?>
            <option value="<?= h($key) ?>"></option>
        <?php endforeach; ?>
    </datalist>

    <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:0.75rem;">
        <div>
            <label>Policy env key</label>
            <input type="text" name="policy_env_key" list="env-key-options" value="<?= h($policyEnvKey) ?>" placeholder="FOUNDERS_V2">
        </div>
        <div>
            <label>Split env key</label>
            <input type="text" name="split_env_key" list="env-key-options" value="<?= h($splitEnvKey) ?>" placeholder="FOUNDERS_V2">
        </div>
        <div>
            <label>Treasury env key</label>
            <input type="text" name="treasury_env_key" list="env-key-options" value="<?= h($treasuryEnvKey) ?>" placeholder="FOUNDERS_V2">
        </div>
    </div>

    <div class="rf-toolbar">
        <button class="rf-btn" type="submit">Load wallets</button>
        <?php if ($selectedCollection): ?>
            <a class="rf-btn rf-btn-ghost" href="/admin/collection-detail.php?id=<?= (int) $selectedCollection['id'] ?>">Open collection detail</a>
        <?php endif; ?>
    </div>
</form>

<?php if ($selectedCollection): ?>
<h2>Selected collection</h2>
<table class="rf-table">
    <tr><th>Name</th><td><?= h((string) $selectedCollection['name']) ?></td></tr>
    <tr><th>Slug</th><td class="rf-mono"><?= h((string) $selectedCollection['slug']) ?></td></tr>
    <tr><th>Network</th><td class="rf-mono"><?= h((string) $selectedCollection['network']) ?></td></tr>
    <tr><th>Stored policy env key</th><td class="rf-mono"><?= h((string) ($selectedCollection['policy_env_key'] ?? '')) ?></td></tr>
    <tr><th>Stored split env key</th><td class="rf-mono"><?= h((string) ($selectedCollection['split_wallet_env_key'] ?? '')) ?></td></tr>
</table>
<?php endif; ?>
<h2>Collection assets</h2>
<?php if (!$tokensTableExists): ?>
    <div class="rf-alert rf-alert-warn">
        The <code>qd_tokens</code> table does not exist yet.
    </div>
<?php elseif ($assetsError !== null): ?>
    <div class="rf-alert rf-alert-error">Asset list failed: <?= h($assetsError) ?></div>
<?php elseif (!$selectedCollection): ?>
    <div class="rf-alert rf-alert-warn">No collection selected.</div>
<?php elseif (empty($collectionAssets)): ?>
    <div class="rf-alert rf-alert-warn">No assets found for this collection.</div>
<?php else: ?>
    <?php if ($assetCountTotal > $assetLimit): ?>
        <div class="rf-alert rf-alert-warn">
            Showing first <?= (int) $assetLimit ?> assets of <?= (int) $assetCountTotal ?> total.
        </div>
    <?php endif; ?>
    <table class="rf-table">
        <thead>
            <tr>
                <th>Item</th>
                <th>State</th>
                <th style="width:180px">Preview</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($collectionAssets as $asset): ?>
            <tr>
                <td>
                    <strong><?= h($asset['rarefolio_token_id']) ?></strong><br>
                    <?= h($asset['title'] !== '' ? $asset['title'] : 'Untitled') ?>
                    <?php if ($asset['character_name'] !== ''): ?>
                        <br><small class="rf-mono"><?= h($asset['character_name']) ?></small>
                    <?php endif; ?>
                    <?php if ($asset['unit'] !== ''): ?>
                        <br><span class="rf-mono" style="font-size:0.72rem;" title="<?= h($asset['unit']) ?>">
                            unit: <?= h(substr($asset['unit'], 0, 24)) ?>…
                        </span>
                    <?php endif; ?>
                    <div class="rf-toolbar" style="margin-top:0.4rem;gap:0.35rem;">
                        <a class="rf-btn rf-btn-ghost" style="padding:0.24rem 0.46rem;font-size:0.72rem" href="/buy.php?token=<?= rawurlencode($asset['rarefolio_token_id']) ?>" target="_blank" rel="noopener">Buy</a>
                        <?php if ($asset['unit'] !== ''): ?>
                            <a class="rf-btn rf-btn-ghost" style="padding:0.24rem 0.46rem;font-size:0.72rem" href="/admin/asset-lookup.php?mode=asset&amp;q=<?= rawurlencode($asset['unit']) ?>">Asset lookup</a>
                        <?php endif; ?>
                    </div>
                </td>
                <td class="rf-mono">
                    primary: <?= h($asset['primary_sale_status'] !== '' ? $asset['primary_sale_status'] : 'unknown') ?><br>
                    listing: <?= h($asset['listing_status'] !== '' ? $asset['listing_status'] : 'unknown') ?><br>
                    owner: <?= h($asset['owner_short'] !== '' ? $asset['owner_short'] : 'not set') ?><br>
                    mint tx:
                    <?php if ($asset['mint_tx_hash'] !== ''): ?>
                        <?= h(substr($asset['mint_tx_hash'], 0, 12)) ?>…
                    <?php else: ?>
                        —
                    <?php endif; ?>
                </td>
                <td>
                    <?php if ($asset['image_url'] !== null): ?>
                        <a href="<?= h($asset['image_url']) ?>" target="_blank" rel="noopener">
                            <img
                                src="<?= h($asset['image_url']) ?>"
                                alt="<?= h($asset['title'] !== '' ? $asset['title'] : $asset['rarefolio_token_id']) ?>"
                                style="display:block;width:160px;max-width:100%;height:92px;object-fit:cover;border:1px solid var(--rf-border);border-radius:6px;background:#0a1322"
                            >
                        </a>
                    <?php elseif ($asset['raw_image'] !== null): ?>
                        <span class="rf-mono" style="font-size:0.72rem;">Image URI not renderable</span><br>
                        <span class="rf-mono" style="font-size:0.68rem;word-break:break-all;"><?= h((string) $asset['raw_image']) ?></span>
                    <?php else: ?>
                        <span class="rf-mono">No image in metadata</span>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>

<h2>Policy wallet</h2>
<?php if ($policyError !== null): ?>
    <div class="rf-alert rf-alert-error">Policy wallet lookup failed: <?= h($policyError) ?></div>
<?php elseif ($policyInfo === null): ?>
    <div class="rf-alert rf-alert-warn">Policy wallet not loaded. Check env key and sidecar status.</div>
<?php else: ?>
    <table class="rf-table">
        <tr><th>Env key</th><td class="rf-mono"><?= h((string) ($policyInfo['env_key'] ?? $policyEnvKey)) ?></td></tr>
        <tr><th>Derived policy id</th><td class="rf-mono"><?= h((string) ($policyInfo['policy_id'] ?? '')) ?></td></tr>
        <tr><th>Derived policy addr</th><td class="rf-mono" style="font-size:0.8rem;"><?= h((string) ($policyInfo['policy_addr'] ?? '')) ?></td></tr>
        <tr><th>Lock slot</th><td class="rf-mono"><?= h((string) ($policyInfo['lock_slot'] ?? 'none')) ?></td></tr>
    </table>
    <?php if ($selectedCollection): ?>
        <?php
            $storedPolicyId = strtolower(trim((string) ($selectedCollection['policy_id'] ?? '')));
            $derivedPolicyId = strtolower(trim((string) ($policyInfo['policy_id'] ?? '')));
            $storedPolicyAddr = trim((string) ($selectedCollection['policy_addr'] ?? ''));
            $derivedPolicyAddr = trim((string) ($policyInfo['policy_addr'] ?? ''));
            $policyMismatch = ($storedPolicyId !== '' && $derivedPolicyId !== '' && $storedPolicyId !== $derivedPolicyId)
                || ($storedPolicyAddr !== '' && $derivedPolicyAddr !== '' && $storedPolicyAddr !== $derivedPolicyAddr);
        ?>
        <?php if ($policyMismatch): ?>
            <div class="rf-alert rf-alert-warn">
                Stored collection policy values do not match current sidecar-derived values.
            </div>
        <?php endif; ?>
    <?php endif; ?>
<?php endif; ?>

<h2>Split wallet</h2>
<?php if ($splitError !== null): ?>
    <div class="rf-alert rf-alert-error">Split wallet lookup failed: <?= h($splitError) ?></div>
<?php elseif ($splitBalance === null): ?>
    <div class="rf-alert rf-alert-warn">Split wallet not loaded. Check env key and sidecar status.</div>
<?php else: ?>
    <table class="rf-table">
        <tr><th>Env key</th><td class="rf-mono"><?= h((string) ($splitBalance['env_key'] ?? $splitEnvKey)) ?></td></tr>
        <tr><th>Wallet addr</th><td class="rf-mono" style="font-size:0.8rem;"><?= h((string) ($splitBalance['wallet_addr'] ?? '')) ?></td></tr>
        <tr><th>Balance ADA</th><td class="rf-mono"><?= h(number_format((float) ($splitBalance['balance_ada'] ?? 0), 6)) ?></td></tr>
        <tr><th>Balance lovelace</th><td class="rf-mono"><?= h((string) ($splitBalance['balance_lovelace'] ?? '0')) ?></td></tr>
    </table>
<?php endif; ?>

<h2>Companion treasury wallet</h2>
<?php if ($treasuryError !== null): ?>
    <div class="rf-alert rf-alert-error">Companion treasury lookup failed: <?= h($treasuryError) ?></div>
<?php elseif ($treasuryBalance === null): ?>
    <div class="rf-alert rf-alert-warn">Companion treasury not loaded. Check env key and sidecar status.</div>
<?php else: ?>
    <table class="rf-table">
        <tr><th>Env key</th><td class="rf-mono"><?= h((string) ($treasuryBalance['env_key'] ?? $treasuryEnvKey)) ?></td></tr>
        <tr><th>Treasury addr</th><td class="rf-mono" style="font-size:0.8rem;"><?= h((string) ($treasuryBalance['treasury_addr'] ?? '')) ?></td></tr>
        <tr><th>Balance ADA</th><td class="rf-mono"><?= h(number_format((float) ($treasuryBalance['balance_ada'] ?? 0), 6)) ?></td></tr>
        <tr><th>Balance lovelace</th><td class="rf-mono"><?= h((string) ($treasuryBalance['balance_lovelace'] ?? '0')) ?></td></tr>
    </table>
<?php endif; ?>

<?php if ($companionUnit !== ''): ?>
    <h2>Companion unit balance</h2>
    <?php if ($treasuryUnitError !== null): ?>
        <div class="rf-alert rf-alert-error">Companion unit lookup failed: <?= h($treasuryUnitError) ?></div>
    <?php elseif ($treasuryUnitBalance !== null): ?>
        <table class="rf-table">
            <tr><th>Env key</th><td class="rf-mono"><?= h((string) ($treasuryUnitBalance['env_key'] ?? $treasuryEnvKey)) ?></td></tr>
            <tr><th>Unit</th><td class="rf-mono"><?= h((string) ($treasuryUnitBalance['unit'] ?? $companionUnit)) ?></td></tr>
            <tr><th>Quantity</th><td class="rf-mono"><?= h((string) ($treasuryUnitBalance['quantity'] ?? '0')) ?></td></tr>
            <tr><th>Has unit</th><td class="rf-mono"><?= !empty($treasuryUnitBalance['has_unit']) ? 'true' : 'false' ?></td></tr>
        </table>
    <?php else: ?>
        <div class="rf-alert rf-alert-warn">Companion unit lookup did not return data.</div>
    <?php endif; ?>
<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>
