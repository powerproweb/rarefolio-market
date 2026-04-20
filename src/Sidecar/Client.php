<?php
declare(strict_types=1);

namespace RareFolio\Sidecar;

use RareFolio\Config;
use RuntimeException;

/**
 * HTTP client for the Node.js/TypeScript Cardano sidecar.
 * PHP never builds transactions itself; it asks the sidecar.
 */
final class Client
{
    private string $baseUrl;

    public function __construct(?string $baseUrl = null)
    {
        $this->baseUrl = rtrim($baseUrl ?? Config::get('SIDECAR_BASE_URL', 'http://localhost:4000'), '/');
    }

    /** Quick liveness check. */
    public function health(): bool
    {
        try {
            $resp = $this->get('/health');
            return ($resp['ok'] ?? false) === true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Ask the sidecar to prepare a mint transaction payload for wallet signing.
     *
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    public function prepareMint(array $payload): array
    {
        return $this->post('/mint/prepare', $payload);
    }

    /**
     * Derive the policy ID and address for a named collection env key.
     * e.g. getPolicyInfoForKey('FOUNDERS') queries GET /mint/policy-id?env_key=FOUNDERS
     *
     * @return array<string,mixed>  { env_key, policy_id, policy_addr, lock_slot, script_type }
     */
    public function getPolicyInfoForKey(string $envKey, ?int $lockSlot = null): array
    {
        $params = ['env_key' => strtoupper($envKey)];
        if ($lockSlot !== null) {
            $params['lock_slot'] = $lockSlot;
        }
        return $this->get('/mint/policy-id?' . http_build_query($params));
    }

    /**
     * Get the current ADA balance of a split wallet.
     *
     * @return array<string,mixed>  { env_key, wallet_addr, balance_lovelace, balance_ada }
     */
    public function getSweepBalance(string $envKey): array
    {
        return $this->get('/sweep/balance/' . rawurlencode(strtoupper($envKey)));
    }

    /**
     * Run a sweep distribution from a split wallet.
     *
     * @param array<int,array{addr:string,pct:float,label:string}> $recipients
     * @return array<string,mixed>  SweepResult
     */
    public function runSweep(string $envKey, array $recipients, int $minLovelace = 20_000_000, bool $submit = true): array
    {
        return $this->post('/sweep/run', [
            'split_wallet_env_key' => strtoupper($envKey),
            'recipients'           => $recipients,
            'min_lovelace'         => $minLovelace,
            'submit'               => $submit,
        ]);
    }

    /**
     * Submit a signed transaction CBOR via the sidecar (which calls Blockfrost).
     *
     * @return array<string,mixed>  { tx_hash: string } on success
     */
    public function submitMint(string $cborHex): array
    {
        return $this->post('/mint/submit', ['cbor_hex' => $cborHex]);
    }

    /**
     * Fetch current on-chain owner of an asset via the sidecar.
     *
     * @return array<string,mixed>|null  null when not found on chain
     */
    public function syncToken(string $unit): ?array
    {
        try {
            return $this->get("/sync/token/$unit");
        } catch (RuntimeException $e) {
            if (str_contains($e->getMessage(), 'HTTP 404')) {
                return null;
            }
            throw $e;
        }
    }

    /**
     * Ask the sidecar about a specific asset (it will call Blockfrost + decode CIP-25).
     *
     * @return array<string,mixed>|null
     */
    public function asset(string $unit): ?array
    {
        try {
            return $this->get("/asset/$unit");
        } catch (RuntimeException $e) {
            if (str_contains($e->getMessage(), 'HTTP 404')) {
                return null;
            }
            throw $e;
        }
    }

    /** @return array<string,mixed> */
    private function get(string $path): array
    {
        return $this->request('GET', $path);
    }

    /**
     * @param array<string,mixed> $body
     * @return array<string,mixed>
     */
    private function post(string $path, array $body): array
    {
        return $this->request('POST', $path, $body);
    }

    /**
     * @param array<string,mixed>|null $body
     * @return array<string,mixed>
     */
    private function request(string $method, string $path, ?array $body = null): array
    {
        $ch = curl_init($this->baseUrl . $path);
        $headers = ['Accept: application/json'];
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_CUSTOMREQUEST  => $method,
        ];
        if ($body !== null) {
            $opts[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $headers[] = 'Content-Type: application/json';
        }
        $opts[CURLOPT_HTTPHEADER] = $headers;
        curl_setopt_array($ch, $opts);

        $resp = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($resp === false) {
            throw new RuntimeException("Sidecar curl error: $err");
        }
        if ($code >= 400) {
            throw new RuntimeException("Sidecar HTTP $code for $path: $resp");
        }
        $data = json_decode((string) $resp, true);
        if (!is_array($data)) {
            throw new RuntimeException("Sidecar returned non-JSON for $path");
        }
        return $data;
    }
}
