<?php
declare(strict_types=1);

namespace RareFolio\Blockfrost;

use RareFolio\Config;
use RuntimeException;

/**
 * Thin Blockfrost REST client.
 *
 * Only the read-only endpoints needed for Phase 1:
 *   - asset lookup by unit (policy_id + asset_name_hex)
 *   - assets under a policy
 *   - current holders of an asset
 *   - tx confirmation
 *   - address asset balance
 */
final class Client
{
    private string $baseUrl;
    private string $apiKey;

    public function __construct(?string $network = null, ?string $apiKey = null)
    {
        $network = $network ?? Config::get('BLOCKFROST_NETWORK', 'preprod');
        $this->apiKey  = $apiKey ?? Config::required('BLOCKFROST_API_KEY');
        $this->baseUrl = self::baseUrlFor($network);
    }

    private static function baseUrlFor(string $network): string
    {
        return match ($network) {
            'mainnet' => 'https://cardano-mainnet.blockfrost.io/api/v0',
            'preprod' => 'https://cardano-preprod.blockfrost.io/api/v0',
            'preview' => 'https://cardano-preview.blockfrost.io/api/v0',
            default   => throw new RuntimeException("Unknown Blockfrost network: $network"),
        };
    }

    /**
     * Fetch a single asset by its `unit` (policy_id . asset_name_hex).
     *
     * @return array<string,mixed>|null  null if 404
     */
    public function asset(string $unit): ?array
    {
        return $this->getJson("/assets/$unit", allow404: true);
    }

    /**
     * List assets under a policy (paginated; returns first page of 100).
     *
     * @return array<int,array<string,mixed>>
     */
    public function assetsByPolicy(string $policyId, int $page = 1, int $count = 100): array
    {
        $data = $this->getJson("/assets/policy/$policyId?page=$page&count=$count");
        return is_array($data) ? $data : [];
    }

    /**
     * Get current holder addresses for an asset (typically 1 for NFTs).
     *
     * @return array<int,array<string,mixed>>
     */
    public function assetAddresses(string $unit): array
    {
        $data = $this->getJson("/assets/$unit/addresses");
        return is_array($data) ? $data : [];
    }

    /**
     * Return the single owning bech32 address for an NFT, or null if not held/burned.
     */
    public function currentOwner(string $unit): ?string
    {
        $rows = $this->assetAddresses($unit);
        foreach ($rows as $row) {
            if ((int) ($row['quantity'] ?? 0) > 0 && !empty($row['address'])) {
                return (string) $row['address'];
            }
        }
        return null;
    }

    /**
     * Look up transaction status; returns null on 404.
     *
     * @return array<string,mixed>|null
     */
    public function tx(string $txHash): ?array
    {
        return $this->getJson("/txs/$txHash", allow404: true);
    }

    /**
     * GET helper with auth header and JSON decoding.
     *
     * @return mixed
     */
    private function getJson(string $path, bool $allow404 = false)
    {
        $ch = curl_init($this->baseUrl . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_HTTPHEADER     => [
                'project_id: ' . $this->apiKey,
                'Accept: application/json',
            ],
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            throw new RuntimeException("Blockfrost curl error: $err");
        }
        if ($code === 404 && $allow404) {
            return null;
        }
        if ($code >= 400) {
            throw new RuntimeException("Blockfrost HTTP $code for $path: $body");
        }

        $decoded = json_decode((string) $body, true);
        if (!is_array($decoded)) {
            throw new RuntimeException("Blockfrost returned non-JSON for $path");
        }
        return $decoded;
    }
}
