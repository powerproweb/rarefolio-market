<?php
/**
 * Public buy-order endpoint.
 *
 * POST /api/buy-order.php
 *
 * Called by buy.php after a buyer has sent, or is confirming, payment.
 * This endpoint now enforces a Stage 3 lazy-mint flow with:
 *   - token row locking,
 *   - idempotency by (token_id, tx_hash),
 *   - one active order per token,
 *   - sidecar mint orchestration for unminted tokens,
 *   - explicit failed-order states on orchestration errors.
 */
declare(strict_types=1);

require_once __DIR__ . '/../src/Config.php';
require_once __DIR__ . '/../src/Db.php';
require_once __DIR__ . '/../src/Api/Cors.php';
require_once __DIR__ . '/../src/Api/RateLimit.php';
require_once __DIR__ . '/../src/Sidecar/Client.php';
require_once __DIR__ . '/../src/Blockfrost/Client.php';

use RareFolio\Config;
use RareFolio\Db;
use RareFolio\Api\Cors;
use RareFolio\Api\RateLimit;
use RareFolio\Sidecar\Client as SidecarClient;
use RareFolio\Blockfrost\Client as BlockfrostClient;

Config::load(__DIR__ . '/../.env');
Cors::apply();
RateLimit::enforce('buy-order');

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
if ($method === 'OPTIONS') { http_response_code(204); exit; }
if ($method !== 'POST') {
    http_response_code(405);
    header('Allow: POST, OPTIONS');
    echo json_encode(['ok' => false, 'error' => 'method not allowed']);
    exit;
}

header('Content-Type: application/json');

$raw = file_get_contents('php://input');
$decoded = json_decode((string)$raw, true);
$body = is_array($decoded) ? $decoded : [];

$tokenId        = trim((string)($body['token_id'] ?? ''));
$buyerAddr      = trim((string)($body['buyer_addr'] ?? ''));
$txHash         = trim((string)($body['tx_hash'] ?? ''));
$amountLovelace = (int)($body['amount_lovelace'] ?? 0);

if ($tokenId === '' || $buyerAddr === '' || $txHash === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'token_id, buyer_addr, and tx_hash are required']);
    exit;
}
if (!preg_match('/^[0-9a-f]{64}$/i', $txHash)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'tx_hash must be a 64-character hex string']);
    exit;
}

$pdo = null;
$orderId = 0;

try {
    $pdo = Db::pdo();
    $pdo->beginTransaction();

    // Lock token row to prevent double-sell races.
    $tStmt = $pdo->prepare(
        "SELECT t.id AS nft_id,
                t.rarefolio_token_id,
                t.collection_slug,
                t.policy_id,
                t.primary_sale_status,
                t.listing_status,
                t.asset_name_hex,
                t.asset_name_utf8,
                t.cip25_json,
                c.split_wallet_addr,
                c.royalty_total_pct,
                c.platform_fee_pct,
                c.primary_sale_price_lovelace AS collection_price,
                c.policy_env_key,
                c.custody_env_key,
                c.lock_slot
           FROM qd_tokens t
           LEFT JOIN qd_collections c ON c.slug = t.collection_slug
          WHERE t.rarefolio_token_id = ?
          LIMIT 1
          FOR UPDATE"
    );
    $tStmt->execute([$tokenId]);
    $token = $tStmt->fetch();
    if (!$token) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'token not found']);
        exit;
    }

    // Idempotency replay for exact tuple (token, payment tx hash).
    $existingStmt = $pdo->prepare(
        "SELECT id, status
           FROM qd_orders
          WHERE rarefolio_token_id = ?
            AND order_tx_hash = ?
          ORDER BY id DESC
          LIMIT 1
          FOR UPDATE"
    );
    $existingStmt->execute([$tokenId, $txHash]);
    $existing = $existingStmt->fetch();
    if ($existing) {
        if ($pdo->inTransaction()) $pdo->commit();

        $existingStatus = (string)($existing['status'] ?? '');
        if (in_array($existingStatus, ['failed', 'refunded'], true)) {
            http_response_code(409);
            echo json_encode([
                'ok'           => false,
                'error'        => 'payment already mapped to a failed order, resolve before retrying',
                'order_id'     => (int)$existing['id'],
                'order_status' => $existingStatus,
            ]);
            exit;
        }

        echo json_encode([
            'ok'           => true,
            'order_id'     => (int)$existing['id'],
            'idempotent'   => true,
            'order_status' => $existingStatus,
        ]);
        exit;
    }

    // Prevent tx hash reuse across different tokens.
    $dupTxStmt = $pdo->prepare(
        "SELECT id, rarefolio_token_id
           FROM qd_orders
          WHERE order_tx_hash = ?
          ORDER BY id DESC
          LIMIT 1
          FOR UPDATE"
    );
    $dupTxStmt->execute([$txHash]);
    $duplicate = $dupTxStmt->fetch();
    if ($duplicate) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        http_response_code(409);
        echo json_encode([
            'ok'       => false,
            'error'    => 'this transaction hash has already been recorded',
            'order_id' => (int)($duplicate['id'] ?? 0),
        ]);
        exit;
    }

    // Prevent another active order from claiming the same token.
    $activeStmt = $pdo->prepare(
        "SELECT id, status, order_tx_hash
           FROM qd_orders
          WHERE rarefolio_token_id = ?
            AND status IN ('pending','signed','submitted','settled')
          ORDER BY id DESC
          LIMIT 1
          FOR UPDATE"
    );
    $activeStmt->execute([$tokenId]);
    $activeOrder = $activeStmt->fetch();
    if ($activeOrder) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        http_response_code(409);
        echo json_encode([
            'ok'                => false,
            'error'             => 'token already has an active order',
            'existing_order_id' => (int)$activeOrder['id'],
            'existing_status'   => (string)$activeOrder['status'],
        ]);
        exit;
    }

    $primarySaleStatus = (string)($token['primary_sale_status'] ?? '');
    if (in_array($primarySaleStatus, ['sold', 'sold_pre_marketplace'], true)) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        http_response_code(409);
        echo json_encode(['ok' => false, 'error' => 'this token has already been sold']);
        exit;
    }
    if (!in_array($primarySaleStatus, ['unminted', 'minted'], true)) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        http_response_code(409);
        echo json_encode(['ok' => false, 'error' => 'token is not in a purchasable primary-sale state']);
        exit;
    }
    if ((string)($token['listing_status'] ?? 'none') !== 'listed_fixed') {
        if ($pdo->inTransaction()) $pdo->rollBack();
        http_response_code(409);
        echo json_encode(['ok' => false, 'error' => 'token is not currently active for purchase']);
        exit;
    }
    // Resolve and lock the active listing row used for this order.
    $listingStmt = $pdo->prepare(
        "SELECT id, sale_format, asking_price_lovelace
           FROM qd_listings
          WHERE nft_id = ?
            AND rarefolio_token_id = ?
            AND status = 'active'
          ORDER BY id DESC
          LIMIT 1
          FOR UPDATE"
    );
    $listingStmt->execute([(int)$token['nft_id'], $tokenId]);
    $listing = $listingStmt->fetch();
    if (!$listing) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        http_response_code(409);
        echo json_encode(['ok' => false, 'error' => 'active listing record not found for token']);
        exit;
    }
    $listingId = (int)($listing['id'] ?? 0);
    if ($listingId <= 0) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'invalid listing record for token']);
        exit;
    }
    if ((string)($listing['sale_format'] ?? 'fixed') !== 'fixed') {
        if ($pdo->inTransaction()) $pdo->rollBack();
        http_response_code(409);
        echo json_encode(['ok' => false, 'error' => 'only fixed-price listings are supported for this order path']);
        exit;
    }

    // Resolve and validate sale amount.
    $configuredPrice = (int)($listing['asking_price_lovelace'] ?? 0);
    if ($configuredPrice <= 0) {
        $configuredPrice = (int)($token['collection_price'] ?? 0);
    }
    if ($amountLovelace > 0 && $configuredPrice > 0 && $amountLovelace !== $configuredPrice) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        http_response_code(409);
        echo json_encode(['ok' => false, 'error' => 'payment amount does not match configured collection price']);
        exit;
    }
    $priceLovelace = $amountLovelace > 0 ? $amountLovelace : $configuredPrice;
    if ($priceLovelace <= 0) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'token does not have an active sale price']);
        exit;
    }

    $splitAddr = trim((string)($token['split_wallet_addr'] ?? ''));
    if ($splitAddr === '') {
        if ($pdo->inTransaction()) $pdo->rollBack();
        http_response_code(409);
        echo json_encode(['ok' => false, 'error' => 'token is not sale-ready, split wallet missing']);
        exit;
    }

    try {
        $paymentVerification = verifyPaymentToSplitWallet($txHash, $splitAddr, $priceLovelace);
    } catch (RuntimeException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $status = (int)$e->getCode();
        if ($status < 400 || $status > 599) $status = 502;
        http_response_code($status);
        echo json_encode([
            'ok'    => false,
            'error' => $e->getMessage(),
        ]);
        exit;
    }

    $royaltyPct = (float)($token['royalty_total_pct'] ?? 8.0);
    $platformPct = (float)($token['platform_fee_pct'] ?? 2.5);
    $royaltyLovelace = (int)round($priceLovelace * $royaltyPct / 100);
    $platformLovelace = (int)round($priceLovelace * $platformPct / 100);
    $sellerNet = $priceLovelace - $royaltyLovelace - $platformLovelace;

    // Create pending order row first.
    $pdo->prepare(
        "INSERT INTO qd_orders
            (listing_id, nft_id, rarefolio_token_id,
             buyer_addr, seller_addr,
             sale_amount_lovelace, platform_fee_lovelace,
             creator_royalty_lovelace, seller_net_lovelace,
             creator_addr, platform_addr,
             order_tx_hash, block_height, status, failure_reason)
         VALUES
            (:listing_id, :nft_id, :tid,
             :buyer, :seller,
             :sale, :platform_fee,
             :royalty, :net,
             :creator, :platform,
             :tx, :block_height, 'pending', NULL)"
    )->execute([
        ':listing_id'   => $listingId,
        ':nft_id'       => (int)$token['nft_id'],
        ':tid'          => $tokenId,
        ':buyer'        => $buyerAddr,
        ':seller'       => $splitAddr,
        ':sale'         => $priceLovelace,
        ':platform_fee' => $platformLovelace,
        ':royalty'      => $royaltyLovelace,
        ':net'          => $sellerNet,
        ':creator'      => $splitAddr,
        ':platform'     => $splitAddr,
        ':tx'           => $txHash,
        ':block_height' => $paymentVerification['block_height'],
    ]);
    $orderId = (int)$pdo->lastInsertId();

    // Reserve token from any parallel checkouts while orchestration runs.
    $pdo->prepare(
        "UPDATE qd_tokens
            SET listing_status = 'none',
                updated_at = NOW()
          WHERE id = ?"
    )->execute([(int)$token['nft_id']]);

    $mintTxHash = null;
    $mintPolicyId = null;
    $mintAssetNameHex = null;

    if ($primarySaleStatus === 'unminted') {
        $sidecar = new SidecarClient();
        $cip25 = decodeCip25Metadata((string)($token['cip25_json'] ?? ''), $tokenId, (string)($token['asset_name_utf8'] ?? ''));
        $assetNameUtf8 = resolveAssetNameUtf8((string)($token['asset_name_utf8'] ?? ''), (string)($token['asset_name_hex'] ?? ''), $tokenId);
        $policyEnvKey = trim((string)($token['policy_env_key'] ?? ''));
        $lockSlot = $token['lock_slot'] !== null ? (int)$token['lock_slot'] : null;

        try {
            $preparePayload = [
                'rarefolio_token_id' => $tokenId,
                'collection_slug'    => (string)$token['collection_slug'],
                'asset_name_utf8'    => $assetNameUtf8,
                'recipient_addr'     => $buyerAddr,
                'cip25'              => $cip25,
            ];
            if ($policyEnvKey !== '') {
                $preparePayload['policy_env_key'] = $policyEnvKey;
            }
            if ($lockSlot !== null && $lockSlot > 0) {
                $preparePayload['lock_slot'] = $lockSlot;
            }

            $prepared = $sidecar->prepareMint($preparePayload);
            $cborHex = trim((string)($prepared['cbor_hex'] ?? ''));
            $mintPolicyId = trim((string)($prepared['policy_id'] ?? ''));
            $mintAssetNameHex = trim((string)($prepared['asset_name_hex'] ?? ''));
            $preparedAssetNameUtf8 = resolveAssetNameUtf8(
                trim((string)($prepared['asset_name_utf8'] ?? '')),
                $mintAssetNameHex,
                $assetNameUtf8
            );

            if ($cborHex === '' || !preg_match('/^[0-9a-f]+$/i', $cborHex)) {
                throw new RuntimeException('sidecar returned invalid mint cbor');
            }
            if ($mintPolicyId === '' || !preg_match('/^[0-9a-f]{56}$/i', $mintPolicyId)) {
                throw new RuntimeException('sidecar returned invalid policy_id');
            }
            if ($mintAssetNameHex === '' || !preg_match('/^[0-9a-f]+$/i', $mintAssetNameHex)) {
                throw new RuntimeException('sidecar returned invalid asset_name_hex');
            }

            $submitted = $sidecar->submitMint($cborHex);
            $mintTxHash = trim((string)($submitted['tx_hash'] ?? ''));
            if ($mintTxHash === '' || !preg_match('/^[0-9a-f]{64}$/i', $mintTxHash)) {
                throw new RuntimeException('sidecar returned invalid mint tx hash');
            }

            $pdo->prepare(
                "UPDATE qd_tokens
                    SET policy_id = :policy_id,
                        asset_name_hex = :asset_name_hex,
                        asset_name_utf8 = :asset_name_utf8,
                        mint_tx_hash = :mint_tx_hash,
                        minted_at = COALESCE(minted_at, NOW()),
                        current_owner_wallet = :buyer_addr,
                        custody_status = 'external',
                        primary_sale_status = 'sold',
                        updated_at = NOW()
                  WHERE id = :nft_id"
            )->execute([
                ':policy_id'       => $mintPolicyId,
                ':asset_name_hex'  => $mintAssetNameHex,
                ':asset_name_utf8' => $preparedAssetNameUtf8,
                ':mint_tx_hash'    => $mintTxHash,
                ':buyer_addr'      => $buyerAddr,
                ':nft_id'          => (int)$token['nft_id'],
            ]);
        } catch (Throwable $e) {
            throw new RuntimeException('sidecar mint orchestration failed: ' . $e->getMessage(), 0, $e);
        }
    } else {
        // Already-minted path: deliver NFT + companion from platform custody to the buyer.
        $custodyEnvKey = strtoupper(trim((string)($token['custody_env_key'] ?? '')));
        $deliverPolicy = strtolower(trim((string)($token['policy_id'] ?? '')));
        $deliverAsset  = strtolower(trim((string)($token['asset_name_hex'] ?? '')));
        $cip25Deliver  = decodeCip25Metadata((string)($token['cip25_json'] ?? ''), $tokenId, (string)($token['asset_name_utf8'] ?? ''));
        $companionUnit = resolveCompanionUnit($cip25Deliver);

        if ($custodyEnvKey === '' || !preg_match('/^[0-9a-f]{56}$/', $deliverPolicy)
            || !preg_match('/^[0-9a-f]+$/', $deliverAsset) || $companionUnit === null) {
            throw new RuntimeException('custody delivery is not configured for this token');
        }

        try {
            $sidecar  = new SidecarClient();
            $delivery = $sidecar->transferPairedAsset([
                'treasury_env_key'   => $custodyEnvKey,
                'recipient_addr'     => $buyerAddr,
                'nft_unit'           => $deliverPolicy . $deliverAsset,
                'companion_unit'     => $companionUnit,
                'companion_quantity' => 1,
                'submit'             => true,
            ]);
            $deliveryTx = trim((string)($delivery['tx_hash'] ?? ''));
            if ($deliveryTx === '' || !preg_match('/^[0-9a-f]{64}$/i', $deliveryTx)) {
                throw new RuntimeException('sidecar returned invalid delivery tx hash');
            }

            $pdo->prepare(
                "UPDATE qd_tokens
                    SET current_owner_wallet = :buyer_addr,
                        custody_status = 'external',
                        primary_sale_status = 'sold',
                        updated_at = NOW()
                  WHERE id = :nft_id"
            )->execute([
                ':buyer_addr' => $buyerAddr,
                ':nft_id'     => (int)$token['nft_id'],
            ]);

            $mintTxHash = $deliveryTx; // record the delivery tx in order metadata
        } catch (Throwable $e) {
            throw new RuntimeException('custody delivery failed: ' . $e->getMessage(), 0, $e);
        }
    }

    $pdo->prepare(
        "UPDATE qd_orders
            SET status = 'submitted',
                failure_reason = NULL,
                updated_at = NOW()
          WHERE id = ?"
    )->execute([$orderId]);

    persistOrderMintMetadata($pdo, $orderId, $mintTxHash, $mintPolicyId, $mintAssetNameHex);

    if ($pdo->inTransaction()) $pdo->commit();

    error_log(
        "[buy-order] Order #{$orderId} submitted: token={$tokenId} buyer={$buyerAddr} " .
        "payment_tx={$txHash} mint_tx=" . ($mintTxHash ?? 'n/a') . " amount={$priceLovelace}"
    );

    echo json_encode(['ok' => true, 'order_id' => $orderId]);
} catch (Throwable $e) {
    $errorMessage = trim($e->getMessage());
    $failureReason = mb_substr($errorMessage !== '' ? $errorMessage : 'unknown orchestration error', 0, 1800);

    if ($pdo instanceof PDO && $pdo->inTransaction()) {
        if ($orderId > 0) {
            try {
                $pdo->prepare(
                    "UPDATE qd_orders
                        SET status = 'failed',
                            failure_reason = :reason,
                            updated_at = NOW()
                      WHERE id = :id"
                )->execute([
                    ':reason' => $failureReason,
                    ':id'     => $orderId,
                ]);
                $pdo->commit();
            } catch (Throwable) {
                if ($pdo->inTransaction()) $pdo->rollBack();
            }
        } else {
            $pdo->rollBack();
        }
    }

    error_log('[buy-order] ERROR: ' . $e->getMessage());
    if (str_contains(strtolower($errorMessage), 'sidecar mint orchestration failed')) {
        http_response_code(502);
        echo json_encode(['ok' => false, 'error' => 'mint orchestration failed, order marked failed for review']);
        exit;
    }
    if (str_contains(strtolower($errorMessage), 'custody delivery failed')) {
        http_response_code(502);
        echo json_encode(['ok' => false, 'error' => 'delivery failed, order marked failed for review']);
        exit;
    }

    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'server error creating order']);
}

/**
 * @return array<string,mixed>
 */
function decodeCip25Metadata(string $json, string $tokenId, string $fallbackName): array
{
    $decoded = json_decode($json, true);
    if (is_array($decoded) && !array_is_list($decoded)) {
        return $decoded;
    }
    $safeName = $fallbackName !== '' ? $fallbackName : $tokenId;
    return [
        'name' => $safeName,
        'rarefolio_token_id' => $tokenId,
    ];
}
function resolveCompanionUnit(array $cip25): ?string
{
    $candidates = [
        $cip25['companion_unit'] ?? null,
        $cip25['silver_shard_unit'] ?? null,
        $cip25['companion']['unit'] ?? null,
        $cip25['attributes']['companion_unit'] ?? null,
        $cip25['attributes']['silver_shard_unit'] ?? null,
    ];
    foreach ($candidates as $c) {
        if (!is_string($c) || trim($c) === '') continue;
        $u = strtolower(preg_replace('/^0x/i', '', trim($c)));
        if (preg_match('/^[0-9a-f]{56,}$/', $u)) return $u;
    }
    return null;
}

function resolveAssetNameUtf8(string $assetNameUtf8, string $assetNameHex, string $fallback): string
{
    $assetNameUtf8 = trim($assetNameUtf8);
    if ($assetNameUtf8 !== '') return $assetNameUtf8;

    $assetNameHex = trim($assetNameHex);
    if ($assetNameHex !== '' && preg_match('/^[0-9a-f]+$/i', $assetNameHex)) {
        $decoded = @hex2bin($assetNameHex);
        if (is_string($decoded) && $decoded !== '') {
            return $decoded;
        }
    }
    return $fallback;
}

/**
 * @return array{block_height:int, received_lovelace:int}
 */
function verifyPaymentToSplitWallet(string $txHash, string $splitAddr, int $expectedLovelace): array
{
    try {
        $bf = new BlockfrostClient();
    } catch (Throwable $e) {
        throw new RuntimeException('payment verification is not configured', 502, $e);
    }

    try {
        $tx = $bf->tx($txHash);
    } catch (Throwable $e) {
        throw new RuntimeException('payment verification unavailable, chain lookup failed', 502, $e);
    }
    if (!is_array($tx)) {
        throw new RuntimeException('payment transaction not yet confirmed on chain', 409);
    }

    try {
        $utxos = $bf->txUtxos($txHash);
    } catch (Throwable $e) {
        throw new RuntimeException('payment verification unavailable, transaction outputs lookup failed', 502, $e);
    }
    if (!is_array($utxos)) {
        throw new RuntimeException('payment transaction outputs unavailable', 502);
    }

    $receivedLovelace = lovelacePaidToAddressFromTxUtxos($utxos, $splitAddr);
    if ($receivedLovelace < $expectedLovelace) {
        throw new RuntimeException('payment amount to split wallet is below required collection price', 409);
    }

    return [
        'block_height'      => (int)($tx['block_height'] ?? 0),
        'received_lovelace' => $receivedLovelace,
    ];
}

function lovelacePaidToAddressFromTxUtxos(array $txUtxos, string $address): int
{
    $sum = 0;
    $target = trim($address);
    $outputs = $txUtxos['outputs'] ?? [];
    if (!is_array($outputs)) return 0;

    foreach ($outputs as $output) {
        if (!is_array($output)) continue;
        if (trim((string)($output['address'] ?? '')) !== $target) continue;
        $amounts = $output['amount'] ?? [];
        if (!is_array($amounts)) continue;
        foreach ($amounts as $entry) {
            if (!is_array($entry)) continue;
            if ((string)($entry['unit'] ?? '') !== 'lovelace') continue;
            $sum += (int)($entry['quantity'] ?? 0);
        }
    }

    return $sum;
}

function qdOrdersColumnExists(PDO $pdo, string $column): bool
{
    static $cache = [];
    if (array_key_exists($column, $cache)) {
        return $cache[$column];
    }

    $stmt = $pdo->prepare(
        "SELECT 1
           FROM information_schema.columns
          WHERE table_schema = DATABASE()
            AND table_name = 'qd_orders'
            AND column_name = ?
          LIMIT 1"
    );
    $stmt->execute([$column]);
    $cache[$column] = (bool)$stmt->fetchColumn();
    return $cache[$column];
}

function persistOrderMintMetadata(PDO $pdo, int $orderId, ?string $mintTxHash, ?string $policyId, ?string $assetNameHex): void
{
    if ($orderId <= 0) return;

    $sets = [];
    $params = [':id' => $orderId];

    if ($mintTxHash !== null && $mintTxHash !== '' && qdOrdersColumnExists($pdo, 'mint_tx_hash')) {
        $sets[] = 'mint_tx_hash = :mint_tx_hash';
        $params[':mint_tx_hash'] = $mintTxHash;
    }

    if ($policyId !== null && $policyId !== '') {
        if (qdOrdersColumnExists($pdo, 'mint_policy_id')) {
            $sets[] = 'mint_policy_id = :mint_policy_id';
            $params[':mint_policy_id'] = $policyId;
        } elseif (qdOrdersColumnExists($pdo, 'policy_id')) {
            $sets[] = 'policy_id = :mint_policy_id';
            $params[':mint_policy_id'] = $policyId;
        }
    }

    if ($assetNameHex !== null && $assetNameHex !== '') {
        if (qdOrdersColumnExists($pdo, 'mint_asset_name_hex')) {
            $sets[] = 'mint_asset_name_hex = :mint_asset_name_hex';
            $params[':mint_asset_name_hex'] = $assetNameHex;
        } elseif (qdOrdersColumnExists($pdo, 'asset_name_hex')) {
            $sets[] = 'asset_name_hex = :mint_asset_name_hex';
            $params[':mint_asset_name_hex'] = $assetNameHex;
        }
    }

    if (empty($sets)) return;

    $sql = "UPDATE qd_orders SET " . implode(', ', $sets) . ", updated_at = NOW() WHERE id = :id";
    $pdo->prepare($sql)->execute($params);
}
