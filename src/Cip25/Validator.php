<?php
declare(strict_types=1);

namespace RareFolio\Cip25;

/**
 * CIP-25 v1 metadata validator tailored to RareFolio rules.
 *
 * A valid submission looks like (after wrapping):
 * {
 *   "721": {
 *     "<policy_id>": {
 *       "<asset_name_utf8>": {
 *         "name":        "Character Name",
 *         "image":       "ipfs://<cid>",
 *         "mediaType":   "image/png",
 *         "description": "Short description" | ["line 1", "line 2"],
 *         "artist":      "Artist Name",
 *         "edition":     "3/50",
 *         "attributes":  { "trait_type": "value", ... },
 *         "rarefolio_token_id": "RF-0001",
 *         "collection":  "genesis",
 *         "website":     "https://rarefolio.io/nft/slug"
 *       }
 *     }
 *   }
 * }
 *
 * Usage:
 *   $result = Validator::validate($metadataArray);
 *   // $result = ['valid' => bool, 'errors' => string[], 'warnings' => string[]]
 */
final class Validator
{
    /** Required top-level keys inside the asset object. */
    private const REQUIRED_KEYS = [
        'name',
        'image',
        'mediaType',
        'artist',
        'edition',
        'rarefolio_token_id',
        'collection',
    ];

    private const RECOMMENDED_KEYS = [
        'description',
        'attributes',
        'website',
    ];

    /** Allowed mediaType prefixes. */
    private const MEDIA_TYPES = [
        'image/png', 'image/jpeg', 'image/gif', 'image/webp', 'image/svg+xml',
        'video/mp4', 'video/webm',
        'audio/mpeg', 'audio/wav',
    ];

    /**
     * @param array<string,mixed> $asset  The raw asset metadata (inner object only).
     * @return array{valid:bool,errors:array<int,string>,warnings:array<int,string>}
     */
    public static function validate(array $asset): array
    {
        $errors = [];
        $warnings = [];

        // 1. Required keys
        foreach (self::REQUIRED_KEYS as $key) {
            if (!array_key_exists($key, $asset) || self::isEmpty($asset[$key])) {
                $errors[] = "Missing required field: `$key`";
            }
        }

        // 2. name
        if (isset($asset['name']) && is_string($asset['name']) && strlen($asset['name']) > 64) {
            $warnings[] = 'Field `name` is longer than 64 characters (wallets may truncate).';
        }

        // 3. image must be ipfs://
        if (!empty($asset['image'])) {
            $image = self::joinIfArray($asset['image']);
            if (!str_starts_with($image, 'ipfs://')) {
                $errors[] = 'Field `image` must be an `ipfs://<cid>` URI, not an HTTPS-only URL.';
            } elseif (!preg_match('#^ipfs://[A-Za-z0-9]+#', $image)) {
                $errors[] = 'Field `image` is not a well-formed ipfs:// URI.';
            }
        }

        // 4. mediaType
        if (!empty($asset['mediaType'])) {
            $mt = (string) $asset['mediaType'];
            $ok = false;
            foreach (self::MEDIA_TYPES as $prefix) {
                if (str_starts_with($mt, $prefix)) {
                    $ok = true;
                    break;
                }
            }
            if (!$ok) {
                $warnings[] = "mediaType `$mt` is unusual; double-check it renders in wallets.";
            }
        }

        // 5. rarefolio_token_id format
        if (!empty($asset['rarefolio_token_id'])) {
            $rid = (string) $asset['rarefolio_token_id'];
            if (!preg_match('/^RF-\d{4,6}$/', $rid)) {
                $errors[] = '`rarefolio_token_id` must match pattern `RF-0001` (RF- followed by 4–6 digits).';
            }
        }

        // 6. edition format (accept "3/50" or "3 of 50")
        if (!empty($asset['edition'])) {
            $ed = (string) $asset['edition'];
            if (!preg_match('#^\d+\s*(?:/|of)\s*\d+$#i', $ed) && !preg_match('/^\d+$/', $ed)) {
                $warnings[] = 'Field `edition` should be formatted like `3/50` or a single integer.';
            }
        }

        // 7. description length (per line)
        if (isset($asset['description'])) {
            $lines = is_array($asset['description']) ? $asset['description'] : [$asset['description']];
            foreach ($lines as $i => $ln) {
                if (!is_string($ln)) {
                    $errors[] = "description[$i] is not a string.";
                    continue;
                }
                if (strlen($ln) > 64) {
                    $warnings[] = "description line " . ($i + 1) . " exceeds 64 chars (" . strlen($ln) . "). Wallets often truncate; split into an array.";
                }
            }
        }

        // 8. attributes must be object not list
        if (isset($asset['attributes'])) {
            if (!is_array($asset['attributes']) || array_is_list($asset['attributes'])) {
                $errors[] = '`attributes` must be a key/value object, not a list.';
            }
        }

        // 9. website sanity
        if (!empty($asset['website'])) {
            $site = (string) $asset['website'];
            if (!filter_var($site, FILTER_VALIDATE_URL)) {
                $warnings[] = 'Field `website` is not a valid URL.';
            }
        }

        // 10. Recommended but missing
        foreach (self::RECOMMENDED_KEYS as $key) {
            if (!array_key_exists($key, $asset) || self::isEmpty($asset[$key])) {
                $warnings[] = "Recommended field missing: `$key`";
            }
        }

        return [
            'valid'    => $errors === [],
            'errors'   => $errors,
            'warnings' => $warnings,
        ];
    }

    /**
     * Build the full CIP-25 label-721 envelope around an asset.
     *
     * @param array<string,mixed> $asset
     * @return array<string,mixed>
     */
    public static function wrap(string $policyId, string $assetNameUtf8, array $asset): array
    {
        return [
            '721' => [
                $policyId => [
                    $assetNameUtf8 => $asset,
                ],
            ],
        ];
    }

    private static function isEmpty(mixed $v): bool
    {
        if ($v === null || $v === '') {
            return true;
        }
        if (is_array($v) && $v === []) {
            return true;
        }
        return false;
    }

    private static function joinIfArray(mixed $v): string
    {
        if (is_array($v)) {
            return implode('', array_map(static fn($x) => (string) $x, $v));
        }
        return (string) $v;
    }
}
