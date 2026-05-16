<?php
declare(strict_types=1);

namespace RareFolio\Cip25;

final class Reader
{
    private const FOUNDERS_BLOCK88_IMAGE_CID = 'bafybeigcsosusr5dvsgfkn4ox3sgqyr3gzmd4cal32guxijygxzpd5x6vy';
    /**
     * @return array<string,mixed>
     */
    public static function decode(string $rawJson): array
    {
        $decoded = json_decode($rawJson, true);
        return is_array($decoded) ? $decoded : [];
    }

    public static function image(array $metadata, ?string $policyId = null, ?string $assetNameUtf8 = null): string
    {
        return self::field($metadata, 'image', $policyId, $assetNameUtf8, '');
    }

    public static function description(array $metadata, ?string $policyId = null, ?string $assetNameUtf8 = null): string
    {
        return self::field($metadata, 'description', $policyId, $assetNameUtf8, ' ');
    }
    public static function normalizeImageUri(
        string $uri,
        ?string $collectionSlug = null,
        ?string $tokenId = null
    ): string {
        $normalized = trim($uri);
        $slug = trim((string) $collectionSlug);
        $token = trim((string) $tokenId);
        $isFoundersCollection = $slug !== '' && str_contains(strtolower($slug), 'founders');
        $isFoundersToken = self::isFoundersTokenId($token);
        $useFoundersFallback = $isFoundersCollection || $isFoundersToken;

        if ($normalized !== '' && str_contains($normalized, 'REPLACE_WITH_CID') && $useFoundersFallback) {
            $normalized = str_replace('REPLACE_WITH_CID', self::FOUNDERS_BLOCK88_IMAGE_CID, $normalized);
        }
        if ($normalized === '' && $isFoundersToken) {
            $normalized = 'ipfs://' . self::FOUNDERS_BLOCK88_IMAGE_CID . '/' . $token . '.jpg';
        }

        return $normalized;
    }

    private static function isFoundersTokenId(string $tokenId): bool
    {
        return preg_match('/^qd-silver-00007(0[5-9]|1[0-2])$/', $tokenId) === 1;
    }

    public static function field(
        array $metadata,
        string $field,
        ?string $policyId = null,
        ?string $assetNameUtf8 = null,
        string $join = ''
    ): string {
        $asset = self::assetNode($metadata, $policyId, $assetNameUtf8);
        if (array_key_exists($field, $asset)) {
            return self::toString($asset[$field], $join);
        }
        if (array_key_exists($field, $metadata)) {
            return self::toString($metadata[$field], $join);
        }
        return '';
    }

    /**
     * @return array<string,mixed>
     */
    public static function assetNode(array $metadata, ?string $policyId = null, ?string $assetNameUtf8 = null): array
    {
        if (self::isAssetNode($metadata)) {
            return $metadata;
        }

        $root = (isset($metadata['721']) && is_array($metadata['721'])) ? $metadata['721'] : $metadata;
        $policy = trim((string) $policyId);
        $assetName = trim((string) $assetNameUtf8);

        if ($policy !== '' && isset($root[$policy]) && is_array($root[$policy])) {
            $policyNode = $root[$policy];
            if ($assetName !== '' && isset($policyNode[$assetName]) && is_array($policyNode[$assetName])) {
                return $policyNode[$assetName];
            }
            $first = self::firstArrayChild($policyNode);
            if ($first !== null) {
                return $first;
            }
        }

        if ($assetName !== '') {
            $named = self::findAssetByName($root, $assetName);
            if ($named !== null) {
                return $named;
            }
        }

        $found = self::findAssetNode($root);
        return $found ?? [];
    }

    private static function toString(mixed $value, string $join): string
    {
        if (is_string($value) || is_int($value) || is_float($value)) {
            return trim((string) $value);
        }
        if (!is_array($value)) {
            return '';
        }

        $parts = [];
        foreach ($value as $item) {
            $part = self::toString($item, $join);
            if ($part !== '') {
                $parts[] = $part;
            }
        }
        if ($parts === []) {
            return '';
        }
        return $join === '' ? implode('', $parts) : trim(implode($join, $parts));
    }

    private static function isAssetNode(array $node): bool
    {
        foreach (['name', 'image', 'description', 'mediaType', 'rarefolio_token_id', 'collection'] as $key) {
            if (array_key_exists($key, $node)) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param array<mixed,mixed> $node
     * @return array<string,mixed>|null
     */
    private static function firstArrayChild(array $node): ?array
    {
        foreach ($node as $value) {
            if (is_array($value)) {
                return $value;
            }
        }
        return null;
    }

    /**
     * @param array<mixed,mixed> $node
     * @return array<string,mixed>|null
     */
    private static function findAssetByName(array $node, string $assetName): ?array
    {
        if (isset($node[$assetName]) && is_array($node[$assetName])) {
            return $node[$assetName];
        }
        foreach ($node as $value) {
            if (!is_array($value)) {
                continue;
            }
            $found = self::findAssetByName($value, $assetName);
            if ($found !== null) {
                return $found;
            }
        }
        return null;
    }

    /**
     * @param array<mixed,mixed> $node
     * @return array<string,mixed>|null
     */
    private static function findAssetNode(array $node): ?array
    {
        if (self::isAssetNode($node)) {
            return $node;
        }
        foreach ($node as $value) {
            if (!is_array($value)) {
                continue;
            }
            $found = self::findAssetNode($value);
            if ($found !== null) {
                return $found;
            }
        }
        return null;
    }
}
