<?php
declare(strict_types=1);

namespace RareFolio\Webhook;

/**
 * Validates inbound Blockfrost webhook signatures.
 *
 * Blockfrost signs each webhook with HMAC-SHA256.
 * The signature header format is:
 *   Blockfrost-Signature: t=<unix_timestamp>,v1=<hmac_hex>
 *
 * The signed payload is: "<timestamp>.<raw_body>"
 *
 * The auth token is set in your Blockfrost dashboard when creating the webhook
 * and must be stored in BLOCKFROST_WEBHOOK_AUTH_TOKEN in .env.
 *
 * Usage:
 *   $raw   = file_get_contents('php://input');
 *   $sig   = $_SERVER['HTTP_BLOCKFROST_SIGNATURE'] ?? '';
 *   $valid = BlockfrostReceiver::validate($secret, $raw, $sig);
 */
final class BlockfrostReceiver
{
    private const MAX_SKEW_SECONDS = 300;

    /**
     * Validates the Blockfrost-Signature header against the raw request body.
     *
     * @param string $secret     Value of BLOCKFROST_WEBHOOK_AUTH_TOKEN
     * @param string $rawBody    Raw (un-decoded) request body bytes
     * @param string $sigHeader  Contents of the Blockfrost-Signature header
     */
    public static function validate(string $secret, string $rawBody, string $sigHeader): bool
    {
        if ($secret === '' || $sigHeader === '') return false;

        // Parse "t=1234567890,v1=abc..."
        $parts = [];
        foreach (explode(',', $sigHeader) as $segment) {
            $pos = strpos($segment, '=');
            if ($pos !== false) {
                $parts[substr($segment, 0, $pos)] = substr($segment, $pos + 1);
            }
        }

        $timestamp = $parts['t'] ?? '';
        $signature = $parts['v1'] ?? '';

        if ($timestamp === '' || $signature === '') return false;

        // Replay protection
        $age = abs(time() - (int) $timestamp);
        if ($age > self::MAX_SKEW_SECONDS) return false;

        // Compute expected HMAC
        $payload  = $timestamp . '.' . $rawBody;
        $expected = hash_hmac('sha256', $payload, $secret);

        return hash_equals($expected, $signature);
    }

    /**
     * Extracts all Cardano addresses that received ADA in this webhook payload.
     * Used to match against qd_collections.split_wallet_addr.
     *
     * @param array<string,mixed> $payload Decoded webhook JSON
     * @return string[]
     */
    public static function extractReceivedAddresses(array $payload): array
    {
        $addrs = [];
        // Blockfrost address-activity webhook payload structure:
        // { "type": "transaction", "payload": [{ "outputs": [{ "address": "addr1...", "amount": [...] }] }] }
        $events = $payload['payload'] ?? [];
        if (!is_array($events)) return $addrs;

        foreach ($events as $event) {
            $outputs = $event['outputs'] ?? [];
            if (!is_array($outputs)) continue;
            foreach ($outputs as $output) {
                $addr = $output['address'] ?? '';
                if (is_string($addr) && str_starts_with($addr, 'addr')) {
                    $addrs[] = $addr;
                }
            }
        }

        return array_values(array_unique($addrs));
    }
}
