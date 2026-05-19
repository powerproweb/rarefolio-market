<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

use RareFolio\Blockfrost\Client as BlockfrostClient;
use RareFolio\Config;
use RareFolio\Sidecar\Client as SidecarClient;

/**
 * @param array<string,mixed> $wallet
 */
function registerWallet(array &$wallets, string $address, string $role, string $envKey, ?string $collectionSlug = null, ?string $source = null): void
{
    $address = trim($address);
    if ($address === '') {
        return;
    }
    $key = strtolower($address);
    if (!isset($wallets[$key])) {
        $wallets[$key] = [
            'address' => $address,
            'roles' => [],
            'env_keys' => [],
            'collection_slugs' => [],
            'sources' => [],
        ];
    }
    if ($role !== '' && !in_array($role, $wallets[$key]['roles'], true)) {
        $wallets[$key]['roles'][] = $role;
    }
    if ($envKey !== '' && !in_array($envKey, $wallets[$key]['env_keys'], true)) {
        $wallets[$key]['env_keys'][] = $envKey;
    }
    if ($collectionSlug !== null && $collectionSlug !== '' && !in_array($collectionSlug, $wallets[$key]['collection_slugs'], true)) {
        $wallets[$key]['collection_slugs'][] = $collectionSlug;
    }
    if ($source !== null && $source !== '' && !in_array($source, $wallets[$key]['sources'], true)) {
        $wallets[$key]['sources'][] = $source;
    }
}

function tableExists(PDO $pdo, string $tableName): bool
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?');
    $stmt->execute([$tableName]);
    return ((int) $stmt->fetchColumn()) > 0;
}

function normalizeEnvKey(?string $value): string
{
    return strtoupper(trim((string) $value));
}

function normalizeBigInt(string $value): string
{
    $value = ltrim(trim($value), '0');
    return $value === '' ? '0' : $value;
}

function addBigInt(string $left, string $right): string
{
    $left = normalizeBigInt($left);
    $right = normalizeBigInt($right);
    $leftLen = strlen($left);
    $rightLen = strlen($right);
    $maxLen = max($leftLen, $rightLen);
    $left = str_pad($left, $maxLen, '0', STR_PAD_LEFT);
    $right = str_pad($right, $maxLen, '0', STR_PAD_LEFT);
    $carry = 0;
    $out = '';
    for ($i = $maxLen - 1; $i >= 0; $i--) {
        $sum = ((int) $left[$i]) + ((int) $right[$i]) + $carry;
        $out = (string) ($sum % 10) . $out;
        $carry = intdiv($sum, 10);
    }
    if ($carry > 0) {
        $out = (string) $carry . $out;
    }
    return normalizeBigInt($out);
}

function compareBigInt(string $left, string $right): int
{
    $left = normalizeBigInt($left);
    $right = normalizeBigInt($right);
    $leftLen = strlen($left);
    $rightLen = strlen($right);
    if ($leftLen !== $rightLen) {
        return $leftLen <=> $rightLen;
    }
    return strcmp($left, $right);
}

function formatAda(string $lovelace): string
{
    $lovelace = normalizeBigInt($lovelace);
    $isZero = $lovelace === '0';
    if ($isZero) {
        return '0';
    }
    if (strlen($lovelace) <= 6) {
        $whole = '0';
        $fraction = str_pad($lovelace, 6, '0', STR_PAD_LEFT);
    } else {
        $whole = substr($lovelace, 0, -6);
        $fraction = substr($lovelace, -6);
    }
    $fraction = rtrim($fraction, '0');
    return $fraction === '' ? $whole : ($whole . '.' . $fraction);
}

function shortAddr(string $addr): string
{
    if (strlen($addr) <= 28) {
        return $addr;
    }
    return substr($addr, 0, 14) . '…' . substr($addr, -10);
}

function decodeAssetNameUtf8(string $assetHex): ?string
{
    if ($assetHex === '' || !ctype_xdigit($assetHex) || (strlen($assetHex) % 2) !== 0) {
        return null;
    }
    $decoded = @hex2bin($assetHex);
    if ($decoded === false || $decoded === '') {
        return null;
    }
    if (!preg_match('/^[\\x20-\\x7E]+$/', $decoded)) {
        return null;
    }
    return $decoded;
}

$manualAddress = trim((string) ($_GET['address'] ?? ''));
$wallets = [];
$warnings = [];
$errors = [];
$network = (string) Config::get('BLOCKFROST_NETWORK', 'preprod');

$hasCollections = tableExists($pdo, 'qd_collections');
$hasTokens = tableExists($pdo, 'qd_tokens');

$tokenLookup = [];
if ($hasTokens) {
    $tokenRows = $pdo->query('SELECT policy_id, asset_name_hex, asset_name_utf8, rarefolio_token_id FROM qd_tokens')->fetchAll();
    foreach ($tokenRows as $row) {
        $policyId = strtolower((string) ($row['policy_id'] ?? ''));
        $assetHex = strtolower((string) ($row['asset_name_hex'] ?? ''));
        $unit = $policyId . $assetHex;
        if ($policyId === '' || $assetHex === '') {
            continue;
        }
        $tokenLookup[$unit] = [
            'token_id' => (string) ($row['rarefolio_token_id'] ?? ''),
            'asset_name_utf8' => (string) ($row['asset_name_utf8'] ?? ''),
        ];
    }
}

$envKeys = [];
if ($hasCollections) {
    $collectionRows = $pdo->query('SELECT slug, policy_env_key, split_wallet_env_key, policy_addr, split_wallet_addr FROM qd_collections ORDER BY id ASC')->fetchAll();
    foreach ($collectionRows as $row) {
        $slug = trim((string) ($row['slug'] ?? ''));
        $policyEnv = normalizeEnvKey((string) ($row['policy_env_key'] ?? ''));
        $splitEnv = normalizeEnvKey((string) ($row['split_wallet_env_key'] ?? ''));
        if ($policyEnv !== '') {
            $envKeys[$policyEnv] = true;
        }
        if ($splitEnv !== '') {
            $envKeys[$splitEnv] = true;
        }

        $policyAddr = trim((string) ($row['policy_addr'] ?? ''));
        if ($policyAddr !== '') {
            registerWallet($wallets, $policyAddr, 'policy', $policyEnv, $slug, 'collection-db');
        }
        $splitAddr = trim((string) ($row['split_wallet_addr'] ?? ''));
        if ($splitAddr !== '') {
            $chosenEnv = $splitEnv !== '' ? $splitEnv : $policyEnv;
            registerWallet($wallets, $splitAddr, 'split', $chosenEnv, $slug, 'collection-db');
        }
    }
}

if ($manualAddress !== '') {
    if (!preg_match('/^(addr1|addr_test1)[0-9a-z]+$/', strtolower($manualAddress))) {
        $warnings[] = 'Manual address does not look like a valid bech32 payment address. It will still be queried as entered.';
    }
    registerWallet($wallets, $manualAddress, 'manual', 'MANUAL', null, 'query-param');
}

$sidecar = new SidecarClient();
$sidecarAlive = $sidecar->health();

if ($sidecarAlive) {
    foreach (array_keys($envKeys) as $envKey) {
        try {
            $policy = $sidecar->getPolicyInfoForKey($envKey);
            $policyAddr = trim((string) ($policy['policy_addr'] ?? ''));
            if ($policyAddr !== '') {
                registerWallet($wallets, $policyAddr, 'policy', $envKey, null, 'sidecar');
            }
        } catch (Throwable $e) {
            $warnings[] = "Policy wallet lookup failed for $envKey: " . $e->getMessage();
        }

        try {
            $treasury = $sidecar->getCompanionTreasuryBalance($envKey);
            $treasuryAddr = trim((string) ($treasury['treasury_addr'] ?? ''));
            if ($treasuryAddr !== '') {
                registerWallet($wallets, $treasuryAddr, 'treasury', $envKey, null, 'sidecar');
            }
        } catch (Throwable $e) {
            $warnings[] = "Companion treasury lookup failed for $envKey: " . $e->getMessage();
        }

        try {
            $split = $sidecar->getSweepBalance($envKey);
            $splitAddr = trim((string) ($split['wallet_addr'] ?? ''));
            if ($splitAddr !== '') {
                registerWallet($wallets, $splitAddr, 'split', $envKey, null, 'sidecar');
            }
        } catch (Throwable $e) {
            $warnings[] = "Split wallet lookup failed for $envKey: " . $e->getMessage();
        }
    }
} elseif (!empty($envKeys)) {
    $warnings[] = 'Sidecar is offline. Wallet discovery is limited to addresses already saved in qd_collections.';
}

$walletDetails = [];

if (!empty($wallets)) {
    try {
        $bf = new BlockfrostClient($network);
        foreach ($wallets as $wallet) {
            $address = (string) $wallet['address'];
            $utxos = [];
            $tokenTotals = [];
            $totalLovelace = '0';
            $walletError = null;

            try {
                $utxoRows = $bf->allAddressUtxos($address, 25, 100);
                foreach ($utxoRows as $row) {
                    $txHash = (string) ($row['tx_hash'] ?? '');
                    $outputIndex = (int) ($row['output_index'] ?? 0);
                    $amounts = is_array($row['amount'] ?? null) ? $row['amount'] : [];

                    $utxoLovelace = '0';
                    $utxoTokens = [];

                    foreach ($amounts as $amount) {
                        if (!is_array($amount)) {
                            continue;
                        }
                        $unit = (string) ($amount['unit'] ?? '');
                        $quantity = normalizeBigInt((string) ($amount['quantity'] ?? '0'));

                        if ($unit === 'lovelace') {
                            $utxoLovelace = addBigInt($utxoLovelace, $quantity);
                            $totalLovelace = addBigInt($totalLovelace, $quantity);
                            continue;
                        }
                        if ($unit === '') {
                            continue;
                        }

                        $tokenTotals[$unit] = isset($tokenTotals[$unit]) ? addBigInt($tokenTotals[$unit], $quantity) : $quantity;
                        $tokenLookupKey = strtolower($unit);
                        $assetHex = substr($unit, 56);
                        $utf8 = $tokenLookup[$tokenLookupKey]['asset_name_utf8'] ?? '';
                        if ($utf8 === '') {
                            $decoded = decodeAssetNameUtf8($assetHex);
                            $utf8 = $decoded ?? '';
                        }
                        $utxoTokens[] = [
                            'unit' => $unit,
                            'quantity' => $quantity,
                            'token_id' => (string) ($tokenLookup[$tokenLookupKey]['token_id'] ?? ''),
                            'asset_name_utf8' => $utf8,
                        ];
                    }

                    usort($utxoTokens, static function (array $a, array $b): int {
                        return compareBigInt((string) $b['quantity'], (string) $a['quantity']);
                    });

                    $utxos[] = [
                        'tx_hash' => $txHash,
                        'output_index' => $outputIndex,
                        'lovelace' => $utxoLovelace,
                        'tokens' => $utxoTokens,
                    ];
                }
            } catch (Throwable $e) {
                $walletError = $e->getMessage();
            }

            uksort($tokenTotals, static function (string $left, string $right) use ($tokenTotals): int {
                return compareBigInt($tokenTotals[$right], $tokenTotals[$left]);
            });

            $tokenRows = [];
            foreach ($tokenTotals as $unit => $quantity) {
                $policyId = substr($unit, 0, 56);
                $assetHex = substr($unit, 56);
                $lookup = $tokenLookup[strtolower($unit)] ?? null;
                $assetUtf8 = trim((string) ($lookup['asset_name_utf8'] ?? ''));
                if ($assetUtf8 === '') {
                    $decoded = decodeAssetNameUtf8($assetHex);
                    $assetUtf8 = $decoded ?? '';
                }
                $tokenRows[] = [
                    'unit' => $unit,
                    'policy_id' => $policyId,
                    'asset_hex' => $assetHex,
                    'quantity' => $quantity,
                    'token_id' => (string) ($lookup['token_id'] ?? ''),
                    'asset_name_utf8' => $assetUtf8,
                ];
            }

            $walletDetails[] = [
                'address' => $address,
                'roles' => $wallet['roles'],
                'env_keys' => $wallet['env_keys'],
                'collection_slugs' => $wallet['collection_slugs'],
                'sources' => $wallet['sources'],
                'utxo_count' => count($utxos),
                'total_lovelace' => $totalLovelace,
                'token_rows' => $tokenRows,
                'utxos' => $utxos,
                'error' => $walletError,
            ];
        }
    } catch (Throwable $e) {
        $errors[] = $e->getMessage();
    }
}

usort($walletDetails, static function (array $left, array $right): int {
    return strcmp((string) $left['address'], (string) $right['address']);
});

$pageTitle = 'Wallet viewer — RareFolio admin';
require __DIR__ . '/includes/header.php';
?>

<h1>Wallet viewer</h1>
<p class="rf-mono">
    Network: <strong><?= h($network) ?></strong> · Sidecar: <strong style="color:<?= $sidecarAlive ? 'var(--rf-ok)' : 'var(--rf-error)' ?>"><?= $sidecarAlive ? 'online' : 'offline' ?></strong>
</p>

<form method="get" class="rf-form" style="max-width:900px">
    <div style="display:grid;grid-template-columns:1fr auto;gap:0.75rem;">
        <input type="text" name="address" value="<?= h($manualAddress) ?>" placeholder="Optional: inspect any wallet address (addr1...)">
        <button class="rf-btn" type="submit">Refresh</button>
    </div>
</form>

<?php foreach ($errors as $msg): ?>
    <div class="rf-alert rf-alert-error"><?= h($msg) ?></div>
<?php endforeach; ?>

<?php foreach ($warnings as $msg): ?>
    <div class="rf-alert rf-alert-warn"><?= h($msg) ?></div>
<?php endforeach; ?>

<?php if (empty($walletDetails)): ?>
    <div class="rf-alert rf-alert-warn">
        No wallets discovered yet. Add collection rows with policy/split env keys or enter a wallet address above.
    </div>
<?php else: ?>
    <div class="rf-toolbar">
        <span class="rf-mono">Wallets: <?= count($walletDetails) ?></span>
    </div>
    <?php foreach ($walletDetails as $wallet): ?>
        <section class="rf-code" style="white-space:normal;margin-bottom:1rem;">
            <div style="display:flex;flex-wrap:wrap;justify-content:space-between;gap:0.8rem;align-items:center;">
                <div>
                    <div style="font-size:1rem;font-weight:600;"><?= h(shortAddr((string) $wallet['address'])) ?></div>
                    <div class="rf-mono"><?= h((string) $wallet['address']) ?></div>
                </div>
                <div style="display:flex;gap:0.4rem;flex-wrap:wrap;">
                    <?php foreach ((array) $wallet['roles'] as $role): ?>
                        <span class="rf-pill"><?= h((string) $role) ?></span>
                    <?php endforeach; ?>
                    <?php foreach ((array) $wallet['env_keys'] as $envKey): ?>
                        <span class="rf-pill" style="border-color:var(--rf-accent);color:var(--rf-accent);"><?= h((string) $envKey) ?></span>
                    <?php endforeach; ?>
                </div>
            </div>

            <?php if (!empty($wallet['collection_slugs'])): ?>
                <div class="rf-mono" style="margin-top:0.35rem;">collections: <?= h(implode(', ', (array) $wallet['collection_slugs'])) ?></div>
            <?php endif; ?>
            <?php if (!empty($wallet['sources'])): ?>
                <div class="rf-mono">sources: <?= h(implode(', ', (array) $wallet['sources'])) ?></div>
            <?php endif; ?>

            <?php if ($wallet['error'] !== null): ?>
                <div class="rf-alert rf-alert-error" style="margin-top:0.75rem;">
                    Failed loading this wallet from Blockfrost: <?= h((string) $wallet['error']) ?>
                </div>
            <?php else: ?>
                <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:0.65rem;margin-top:0.75rem;">
                    <div>
                        <div class="rf-mono">UTXOs</div>
                        <div style="font-size:1.35rem;"><?= (int) $wallet['utxo_count'] ?></div>
                    </div>
                    <div>
                        <div class="rf-mono">ADA</div>
                        <div style="font-size:1.35rem;"><?= h(formatAda((string) $wallet['total_lovelace'])) ?></div>
                    </div>
                    <div>
                        <div class="rf-mono">Distinct tokens</div>
                        <div style="font-size:1.35rem;"><?= count((array) $wallet['token_rows']) ?></div>
                    </div>
                </div>

                <?php if (!empty($wallet['token_rows'])): ?>
                    <h3>Token totals</h3>
                    <table class="rf-table">
                        <thead>
                            <tr>
                                <th>Token</th>
                                <th>Qty</th>
                                <th>Policy</th>
                                <th>Asset hex</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ((array) $wallet['token_rows'] as $token): ?>
                            <tr>
                                <td>
                                    <?php if ((string) $token['token_id'] !== ''): ?>
                                        <strong><?= h((string) $token['token_id']) ?></strong><br>
                                    <?php endif; ?>
                                    <?php if ((string) $token['asset_name_utf8'] !== ''): ?>
                                        <span class="rf-mono"><?= h((string) $token['asset_name_utf8']) ?></span>
                                    <?php else: ?>
                                        <span class="rf-mono">[non-utf8 asset name]</span>
                                    <?php endif; ?>
                                </td>
                                <td class="rf-mono"><?= h((string) $token['quantity']) ?></td>
                                <td class="rf-mono"><?= h((string) $token['policy_id']) ?></td>
                                <td class="rf-mono"><?= h((string) $token['asset_hex']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>

                <details style="margin-top:0.85rem;">
                    <summary class="rf-mono" style="cursor:pointer;">UTXO breakdown (<?= (int) $wallet['utxo_count'] ?>)</summary>
                    <table class="rf-table" style="margin-top:0.65rem;">
                        <thead>
                            <tr>
                                <th>UTXO</th>
                                <th>ADA</th>
                                <th>Assets</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ((array) $wallet['utxos'] as $utxo): ?>
                            <tr>
                                <td class="rf-mono"><?= h((string) $utxo['tx_hash']) ?>#<?= (int) $utxo['output_index'] ?></td>
                                <td class="rf-mono"><?= h(formatAda((string) $utxo['lovelace'])) ?></td>
                                <td>
                                    <?php if (empty($utxo['tokens'])): ?>
                                        <span class="rf-mono">—</span>
                                    <?php else: ?>
                                        <?php foreach ((array) $utxo['tokens'] as $item): ?>
                                            <div class="rf-mono" style="margin-bottom:0.25rem;">
                                                qty <?= h((string) $item['quantity']) ?>
                                                <?php if ((string) $item['token_id'] !== ''): ?>
                                                    · <strong><?= h((string) $item['token_id']) ?></strong>
                                                <?php endif; ?>
                                                <?php if ((string) $item['asset_name_utf8'] !== ''): ?>
                                                    · <?= h((string) $item['asset_name_utf8']) ?>
                                                <?php endif; ?>
                                                <br><?= h((string) $item['unit']) ?>
                                            </div>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </details>
            <?php endif; ?>
        </section>
    <?php endforeach; ?>
<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>
