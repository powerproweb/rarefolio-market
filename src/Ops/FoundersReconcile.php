<?php
declare(strict_types=1);

namespace RareFolio\Ops;

use PDO;
use RuntimeException;
use Throwable;

final class FoundersReconcile
{
    /** @var string[] */
    private const TOKENS = [
        'qd-silver-0000705',
        'qd-silver-0000706',
        'qd-silver-0000707',
        'qd-silver-0000708',
        'qd-silver-0000709',
        'qd-silver-0000710',
        'qd-silver-0000711',
        'qd-silver-0000712',
    ];

    /** @var string[] */
    private const LISTING_REPAIR_TOKENS = [
        'qd-silver-0000706',
        'qd-silver-0000707',
        'qd-silver-0000708',
        'qd-silver-0000709',
        'qd-silver-0000710',
        'qd-silver-0000711',
        'qd-silver-0000712',
    ];

    /**
     * @return array<string,mixed>
     */
    public static function run(PDO $pdo): array
    {
        $nowUtc = gmdate('Y-m-d H:i:s');
        $runId = gmdate('Ymd\\THis\\Z');
        $backupDir = '/home/rarefolio/rf_storage/ops_backups';
        if (!is_dir($backupDir) || !is_writable($backupDir)) {
            $backupDir = sys_get_temp_dir();
        }
        $backupPath = rtrim($backupDir, '/\\') . '/founders_reconcile_snapshot_' . $runId . '.json';

        $pre = self::fetchSnapshot($pdo, self::TOKENS);
        $preByToken = self::indexByToken($pre['tokens']);
        $queueByToken = self::indexLatestConfirmedQueueByToken($pre['mint_queue']);
        $activeListingsByToken = self::indexActiveListingByToken($pre['listings']);

        $issues = [];
        $actions = [
            'token_updates' => [],
            'listings_created' => [],
            'listing_status_updates' => [],
        ];
        $snapshotData = [
            'run_id' => $runId,
            'captured_at_utc' => gmdate('c'),
            'pre_reconcile' => $pre,
        ];
        self::writeSnapshot($backupPath, $snapshotData);

        $pdo->beginTransaction();
        try {
            $updateTokenStmt = $pdo->prepare(
                "UPDATE qd_tokens
                 SET mint_tx_hash = :mint_tx_hash,
                     minted_at = :minted_at,
                     primary_sale_status = :primary_sale_status,
                     updated_at = NOW()
                 WHERE rarefolio_token_id = :token_id"
            );

            foreach (self::TOKENS as $tokenId) {
                $tokenRow = $preByToken[$tokenId] ?? null;
                $queueRow = $queueByToken[$tokenId] ?? null;

                if ($tokenRow === null) {
                    $issues[] = "missing qd_tokens row: {$tokenId}";
                    continue;
                }
                if ($queueRow === null) {
                    $issues[] = "missing confirmed qd_mint_queue row: {$tokenId}";
                }

                $currentMintTx = (string)($tokenRow['mint_tx_hash'] ?? '');
                $queueTx = $queueRow !== null ? (string)($queueRow['tx_hash'] ?? '') : '';
                $targetMintTx = $currentMintTx !== '' ? $currentMintTx : $queueTx;

                $currentMintedAt = (string)($tokenRow['minted_at'] ?? '');
                $queueConfirmedAt = $queueRow !== null ? (string)($queueRow['confirmed_at'] ?? '') : '';
                $queueUpdatedAt = $queueRow !== null ? (string)($queueRow['updated_at'] ?? '') : '';
                $targetMintedAt = $currentMintedAt;
                if ($targetMintedAt === '' && $queueRow !== null) {
                    $targetMintedAt = $queueConfirmedAt !== '' ? $queueConfirmedAt : ($queueUpdatedAt !== '' ? $queueUpdatedAt : $nowUtc);
                }

                $currentPrimary = (string)($tokenRow['primary_sale_status'] ?? '');
                $targetPrimary = $currentPrimary;
                if ($tokenId !== 'qd-silver-0000705' && $currentPrimary === 'unminted' && $targetMintTx !== '') {
                    $targetPrimary = 'minted';
                }

                $shouldUpdate = ($targetMintTx !== $currentMintTx)
                    || ($targetMintedAt !== $currentMintedAt)
                    || ($targetPrimary !== $currentPrimary);

                if ($shouldUpdate) {
                    $updateTokenStmt->execute([
                        ':mint_tx_hash' => $targetMintTx !== '' ? $targetMintTx : null,
                        ':minted_at' => $targetMintedAt !== '' ? $targetMintedAt : null,
                        ':primary_sale_status' => $targetPrimary,
                        ':token_id' => $tokenId,
                    ]);
                    $actions['token_updates'][] = [
                        'token' => $tokenId,
                        'mint_tx_hash_from' => $currentMintTx !== '' ? $currentMintTx : null,
                        'mint_tx_hash_to' => $targetMintTx !== '' ? $targetMintTx : null,
                        'minted_at_from' => $currentMintedAt !== '' ? $currentMintedAt : null,
                        'minted_at_to' => $targetMintedAt !== '' ? $targetMintedAt : null,
                        'primary_sale_from' => $currentPrimary,
                        'primary_sale_to' => $targetPrimary,
                    ];
                }
            }

            $template = $activeListingsByToken['qd-silver-0000705'] ?? null;
            if ($template === null) {
                foreach ($activeListingsByToken as $row) {
                    $template = $row;
                    break;
                }
            }
            if ($template === null) {
                $template = [
                    'sale_format' => 'fixed',
                    'asking_price_lovelace' => 150000000,
                    'reserve_price_lovelace' => null,
                    'currency' => 'ADA',
                    'seller_user_id' => null,
                    'seller_wallet_id' => null,
                    'seller_addr' => (string)($preByToken['qd-silver-0000705']['current_owner_wallet'] ?? ''),
                ];
            }

            if ((string)($template['seller_addr'] ?? '') === '') {
                foreach ($pre['tokens'] as $row) {
                    $candidate = (string)($row['current_owner_wallet'] ?? '');
                    if ($candidate !== '') {
                        $template['seller_addr'] = $candidate;
                        break;
                    }
                }
            }
            if ((string)($template['seller_addr'] ?? '') === '') {
                throw new RuntimeException('cannot derive seller_addr template for listing inserts');
            }

            $insertListingStmt = $pdo->prepare(
                "INSERT INTO qd_listings
                    (nft_id, rarefolio_token_id, seller_user_id, seller_wallet_id, seller_addr,
                     sale_format, asking_price_lovelace, reserve_price_lovelace, currency,
                     starts_at, ends_at, status, created_at, updated_at)
                 VALUES
                    (:nft_id, :token_id, :seller_user_id, :seller_wallet_id, :seller_addr,
                     :sale_format, :asking_price_lovelace, :reserve_price_lovelace, :currency,
                     NOW(), NULL, 'active', NOW(), NOW())"
            );

            foreach (self::LISTING_REPAIR_TOKENS as $tokenId) {
                if (isset($activeListingsByToken[$tokenId])) {
                    continue;
                }
                $tokenRow = $preByToken[$tokenId] ?? null;
                if ($tokenRow === null) {
                    $issues[] = "cannot create listing, missing token row: {$tokenId}";
                    continue;
                }
                $nftId = (int)($tokenRow['id'] ?? 0);
                if ($nftId <= 0) {
                    $issues[] = "cannot create listing, invalid nft id: {$tokenId}";
                    continue;
                }

                $insertListingStmt->execute([
                    ':nft_id' => $nftId,
                    ':token_id' => $tokenId,
                    ':seller_user_id' => $template['seller_user_id'] !== null ? (int)$template['seller_user_id'] : null,
                    ':seller_wallet_id' => $template['seller_wallet_id'] !== null ? (int)$template['seller_wallet_id'] : null,
                    ':seller_addr' => (string)$template['seller_addr'],
                    ':sale_format' => (string)($template['sale_format'] ?? 'fixed'),
                    ':asking_price_lovelace' => $template['asking_price_lovelace'] !== null ? (int)$template['asking_price_lovelace'] : null,
                    ':reserve_price_lovelace' => $template['reserve_price_lovelace'] !== null ? (int)$template['reserve_price_lovelace'] : null,
                    ':currency' => (string)($template['currency'] ?? 'ADA'),
                ]);

                $actions['listings_created'][] = [
                    'token' => $tokenId,
                    'listing_id' => (int)$pdo->lastInsertId(),
                ];
            }

            $mid = self::fetchSnapshot($pdo, self::TOKENS);
            $midByToken = self::indexByToken($mid['tokens']);
            $midActiveListingsByToken = self::indexActiveListingByToken($mid['listings']);

            $updateListingStatusStmt = $pdo->prepare(
                "UPDATE qd_tokens
                 SET listing_status = :listing_status,
                     updated_at = NOW()
                 WHERE rarefolio_token_id = :token_id"
            );

            foreach (self::TOKENS as $tokenId) {
                $tokenRow = $midByToken[$tokenId] ?? null;
                if ($tokenRow === null) {
                    continue;
                }
                $currentStatus = (string)($tokenRow['listing_status'] ?? 'none');
                $active = $midActiveListingsByToken[$tokenId] ?? null;
                $targetStatus = $active !== null
                    ? self::mapSaleFormatToTokenListingStatus((string)($active['sale_format'] ?? ''))
                    : 'none';

                if ($targetStatus !== $currentStatus) {
                    $updateListingStatusStmt->execute([
                        ':listing_status' => $targetStatus,
                        ':token_id' => $tokenId,
                    ]);
                    $actions['listing_status_updates'][] = [
                        'token' => $tokenId,
                        'from' => $currentStatus,
                        'to' => $targetStatus,
                    ];
                }
            }

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $snapshotData['failed_at_utc'] = gmdate('c');
            $snapshotData['error'] = $e->getMessage();
            $snapshotData['actions'] = $actions;
            $snapshotData['issues'] = $issues;
            self::writeSnapshot($backupPath, $snapshotData);

            return [
                'ok' => false,
                'error' => $e->getMessage(),
                'snapshot_path' => $backupPath,
                'actions' => $actions,
                'issues' => $issues,
            ];
        }

        $post = self::fetchSnapshot($pdo, self::TOKENS);
        $postByToken = self::indexByToken($post['tokens']);
        $postActiveListingsByToken = self::indexActiveListingByToken($post['listings']);

        $checks = [
            'all_tokens_present' => count($postByToken) === count(self::TOKENS),
            'all_tokens_have_mint_tx' => true,
            'all_tokens_not_unminted' => true,
            'listing_repair_tokens_active_listed' => true,
            'listing_repair_tokens_listing_status_synced' => true,
        ];

        foreach (self::TOKENS as $tokenId) {
            $row = $postByToken[$tokenId] ?? null;
            if ($row === null) {
                $checks['all_tokens_present'] = false;
                continue;
            }
            $mintTx = (string)($row['mint_tx_hash'] ?? '');
            if ($mintTx === '') {
                $checks['all_tokens_have_mint_tx'] = false;
                $issues[] = "post-check missing mint_tx_hash: {$tokenId}";
            }
            $primary = (string)($row['primary_sale_status'] ?? '');
            if ($primary === 'unminted') {
                $checks['all_tokens_not_unminted'] = false;
                $issues[] = "post-check still unminted: {$tokenId}";
            }
        }

        foreach (self::LISTING_REPAIR_TOKENS as $tokenId) {
            $row = $postByToken[$tokenId] ?? null;
            if ($row === null) {
                $checks['listing_repair_tokens_active_listed'] = false;
                $checks['listing_repair_tokens_listing_status_synced'] = false;
                continue;
            }
            $active = $postActiveListingsByToken[$tokenId] ?? null;
            if ($active === null) {
                $checks['listing_repair_tokens_active_listed'] = false;
                $issues[] = "post-check missing active listing: {$tokenId}";
                continue;
            }
            $targetListingStatus = self::mapSaleFormatToTokenListingStatus((string)($active['sale_format'] ?? ''));
            $actualListingStatus = (string)($row['listing_status'] ?? 'none');
            if ($targetListingStatus !== $actualListingStatus) {
                $checks['listing_repair_tokens_listing_status_synced'] = false;
                $issues[] = "post-check listing status drift {$tokenId}: expected {$targetListingStatus}, got {$actualListingStatus}";
            }
        }

        $snapshotData['completed_at_utc'] = gmdate('c');
        $snapshotData['post_reconcile'] = $post;
        $snapshotData['actions'] = $actions;
        $snapshotData['issues'] = $issues;
        $snapshotData['checks'] = $checks;
        self::writeSnapshot($backupPath, $snapshotData);

        return [
            'ok' => true,
            'snapshot_path' => $backupPath,
            'actions' => $actions,
            'issues' => $issues,
            'checks' => $checks,
        ];
    }

    /**
     * @param string[] $tokens
     * @return array{tokens:array<int,array<string,mixed>>,mint_queue:array<int,array<string,mixed>>,listings:array<int,array<string,mixed>>,collections:array<int,array<string,mixed>>}
     */
    private static function fetchSnapshot(PDO $pdo, array $tokens): array
    {
        $in = implode(',', array_fill(0, count($tokens), '?'));

        $tStmt = $pdo->prepare(
            "SELECT id, rarefolio_token_id, collection_slug, policy_id, asset_name_hex, asset_name_utf8,
                    mint_tx_hash, minted_at, current_owner_wallet, custody_status, listing_status,
                    primary_sale_status, updated_at
             FROM qd_tokens
             WHERE rarefolio_token_id IN ($in)
             ORDER BY rarefolio_token_id"
        );
        $tStmt->execute($tokens);
        $tokenRows = $tStmt->fetchAll(PDO::FETCH_ASSOC);

        $qStmt = $pdo->prepare(
            "SELECT id, rarefolio_token_id, collection_slug, policy_id, status, tx_hash, submitted_at, confirmed_at, updated_at
             FROM qd_mint_queue
             WHERE rarefolio_token_id IN ($in)
             ORDER BY rarefolio_token_id"
        );
        $qStmt->execute($tokens);
        $queueRows = $qStmt->fetchAll(PDO::FETCH_ASSOC);

        $lStmt = $pdo->prepare(
            "SELECT id, nft_id, rarefolio_token_id, seller_user_id, seller_wallet_id, seller_addr,
                    sale_format, asking_price_lovelace, reserve_price_lovelace, currency,
                    starts_at, ends_at, status, updated_at
             FROM qd_listings
             WHERE rarefolio_token_id IN ($in)
             ORDER BY rarefolio_token_id, id"
        );
        $lStmt->execute($tokens);
        $listingRows = $lStmt->fetchAll(PDO::FETCH_ASSOC);

        $cStmt = $pdo->query(
            "SELECT id, slug, name, policy_env_key, policy_id, network, edition_size, primary_minted_count, all_primary_minted
             FROM qd_collections
             WHERE slug LIKE 'silverbar-01-founders%'
             ORDER BY id"
        );
        $collectionRows = $cStmt->fetchAll(PDO::FETCH_ASSOC);

        return [
            'tokens' => $tokenRows,
            'mint_queue' => $queueRows,
            'listings' => $listingRows,
            'collections' => $collectionRows,
        ];
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     * @return array<string,array<string,mixed>>
     */
    private static function indexByToken(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $token = (string)($row['rarefolio_token_id'] ?? '');
            if ($token !== '') {
                $out[$token] = $row;
            }
        }
        return $out;
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     * @return array<string,array<string,mixed>>
     */
    private static function indexActiveListingByToken(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            if ((string)($row['status'] ?? '') !== 'active') {
                continue;
            }
            $token = (string)($row['rarefolio_token_id'] ?? '');
            if ($token !== '' && !isset($out[$token])) {
                $out[$token] = $row;
            }
        }
        return $out;
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     * @return array<string,array<string,mixed>>
     */
    private static function indexLatestConfirmedQueueByToken(array $rows): array
    {
        $out = [];
        $bestTs = [];
        $bestId = [];

        foreach ($rows as $row) {
            if ((string)($row['status'] ?? '') !== 'confirmed') {
                continue;
            }

            $token = (string)($row['rarefolio_token_id'] ?? '');
            if ($token === '') {
                continue;
            }

            $ts = 0;
            foreach (['confirmed_at', 'updated_at', 'submitted_at'] as $k) {
                $v = trim((string)($row[$k] ?? ''));
                if ($v === '') {
                    continue;
                }
                $parsed = strtotime($v);
                if ($parsed !== false) {
                    $ts = (int)$parsed;
                    break;
                }
            }
            $id = (int)($row['id'] ?? 0);

            if (!isset($out[$token])) {
                $out[$token] = $row;
                $bestTs[$token] = $ts;
                $bestId[$token] = $id;
                continue;
            }

            if ($ts > ($bestTs[$token] ?? 0) || ($ts === ($bestTs[$token] ?? 0) && $id > ($bestId[$token] ?? 0))) {
                $out[$token] = $row;
                $bestTs[$token] = $ts;
                $bestId[$token] = $id;
            }
        }

        return $out;
    }

    private static function mapSaleFormatToTokenListingStatus(string $saleFormat): string
    {
        return match ($saleFormat) {
            'fixed' => 'listed_fixed',
            'auction' => 'listed_auction',
            'offer_only' => 'offer_only',
            default => 'none',
        };
    }

    /**
     * @param array<string,mixed> $snapshotData
     */
    private static function writeSnapshot(string $path, array $snapshotData): void
    {
        file_put_contents(
            $path,
            json_encode(
                $snapshotData,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            )
        );
    }
}
