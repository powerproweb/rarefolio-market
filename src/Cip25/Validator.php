<?php
declare(strict_types=1);

namespace RareFolio\Cip25;

/**
 * CIP-25 v1 metadata validator tailored to RareFolio rules.
 *
 * KEY RULE: Cardano's protocol limits each individual metadata string
 * to 64 bytes. There is no limit on the NUMBER of fields or nesting depth.
 * Use Validator::sanitize() to auto-split any long strings before saving.
 *
 * A valid submission looks like (after wrapping):
 * {
 *   "721": {
 *     "<policy_id>": {
 *       "<asset_name_utf8>": {
 *         "name":              "Character Name",
 *         "image":             "ipfs://<cid>",
 *         "mediaType":         "image/jpeg",
 *         "description":       ["line 1 (<=64 bytes)", "line 2 ..."],
 *         "artist":            "RareFolio",
 *         "edition":           "1/8",
 *         "attributes":        { "any_key": "any_value", ... },
 *         "rarefolio_token_id": "qd-silver-0000705",
 *         "collection":        "silverbar-01-founders",
 *         "website":           "https://rarefolio.io/nft/...",
 *         "any_custom_field":  "any value — unlimited fields allowed"
 *       }
 *     }
 *   }
 * }
 *
 * Usage:
 *   $clean  = Validator::sanitize($asset);           // auto-split long strings
 *   $result = Validator::validate($clean);            // check for errors
 *   $wrapped = Validator::wrap($policyId, $name, $clean);
 */
final class Validator
{
    /** Maximum bytes per string value — Cardano protocol limit. */
    public const MAX_STRING_BYTES = 64;

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

    // =========================================================================
    // String sanitiser — CALL THIS BEFORE validate() AND BEFORE SAVING
    // =========================================================================

    /**
     * Recursively walks every value in the metadata tree and splits any string
     * that exceeds 64 bytes into an array of <=64-byte chunks.
     *
     * This is the correct CIP-25 approach for long descriptions, attributes,
     * or any custom field. Wallets and explorers reassemble the array into one
     * continuous string for display.
     *
     * You can have as many fields as you like — this sanitiser handles all of
     * them automatically. There is no limit on the number of custom fields.
     *
     * @param array<string,mixed> $asset  The asset metadata object
     * @return array<string,mixed>        Sanitised copy — safe for on-chain use
     */
    public static function sanitize(array $asset): array
    {
        $result = [];
        foreach ($asset as $key => $value) {
            $result[$key] = self::sanitizeValue($value);
        }
        return $result;
    }

    /**
     * Recursively sanitise a single value.
     * Strings    → split at 64-byte boundaries (multibyte-safe)
     * Arrays     → recursively sanitised
     * Everything → returned unchanged
     *
     * @param mixed $value
     * @return mixed
     */
    public static function sanitizeValue(mixed $value): mixed
    {
        if (is_string($value)) {
            return strlen($value) <= self::MAX_STRING_BYTES
                ? $value
                : self::splitString($value);
        }

        if (is_array($value)) {
            $out = [];
            foreach ($value as $k => $v) {
                $out[$k] = self::sanitizeValue($v);
            }
            return $out;
        }

        // int, float, bool, null — pass through unchanged
        return $value;
    }

    /**
     * Split a string into an array of chunks where each chunk is <= 64 bytes.
     * Splitting is done on UTF-8 character boundaries so multibyte characters
     * are never severed.
     *
     * @return string[]
     */
    public static function splitString(string $s, int $maxBytes = self::MAX_STRING_BYTES): array
    {
        if (strlen($s) <= $maxBytes) {
            return [$s];
        }

        $chunks = [];
        while ($s !== '') {
            if (strlen($s) <= $maxBytes) {
                $chunks[] = $s;
                break;
            }

            // Start with $maxBytes and back off if we've cut a multibyte char
            $len = $maxBytes;
            while ($len > 0 && (ord($s[$len]) & 0xC0) === 0x80) {
                // $s[$len] is a UTF-8 continuation byte — back off one more
                $len--;
            }

            $chunks[] = substr($s, 0, $len);
            $s        = substr($s, $len);
        }

        return $chunks;
    }

    // =========================================================================
    // Validator
    // =========================================================================

    /**
     * Validate asset metadata (call sanitize() first to pre-clean long strings).
     *
     * @param array<string,mixed> $asset  The raw asset metadata (inner object).
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

        // 2. name — warn if > 64 bytes (sanitize() would split it; some wallets
        //    only show the first chunk of an array for the display name)
        if (isset($asset['name'])) {
            $name = self::joinIfArray($asset['name']);
            if (strlen($name) > 64) {
                $warnings[] = '`name` exceeds 64 bytes. It will be split into an array by sanitize(). '
                    . 'Some wallets display only the first chunk as the token name — keep the '
                    . 'most recognisable part in the first 64 bytes.';
            }
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
                if (str_starts_with($mt, $prefix)) { $ok = true; break; }
            }
            if (!$ok) {
                $warnings[] = "mediaType `$mt` is unusual; double-check it renders in wallets.";
            }
        }

        // 5. rarefolio_token_id — flexible format (RF-0001, qd-silver-0000705, etc.)
        if (!empty($asset['rarefolio_token_id'])) {
            $rid = (string) $asset['rarefolio_token_id'];
            if (strlen($rid) < 3 || strlen($rid) > 64) {
                $errors[] = '`rarefolio_token_id` must be 3–64 characters (e.g. qd-silver-0000705).';
            }
        }

        // 6. edition format (accept "3/50", "1 of 8", "1")
        if (!empty($asset['edition'])) {
            $ed = (string) $asset['edition'];
            if (!preg_match('#^\d+\s*(?:/|of)\s*\d+$#i', $ed) && !preg_match('/^\d+$/', $ed)) {
                $warnings[] = 'Field `edition` should be formatted like `1/8` or a single integer.';
            }
        }

        // 7. Check that all string values are within the 64-byte limit.
        //    If sanitize() was called first this check is purely for confirmation.
        $longFields = self::findLongStrings($asset);
        if (!empty($longFields)) {
            $warnings[] = 'The following fields have string values > 64 bytes and have NOT been '
                . 'auto-split yet. Call Validator::sanitize() before saving: '
                . implode(', ', $longFields);
        }

        // 8. attributes must be object not list (unless intentionally a list)
        if (isset($asset['attributes'])) {
            if (!is_array($asset['attributes']) || array_is_list($asset['attributes'])) {
                $warnings[] = '`attributes` is a list array. This is valid CIP-25 but most '
                    . 'explorers expect a key/value object for trait display. '
                    . 'Use {"trait": "value"} unless you have a specific reason for a list.';
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

    // =========================================================================
    // Wrapper helper
    // =========================================================================

    /**
     * Build the full CIP-25 label-721 envelope, auto-sanitising long strings.
     *
     * @param array<string,mixed> $asset
     * @return array<string,mixed>
     */
    public static function wrap(string $policyId, string $assetNameUtf8, array $asset): array
    {
        // Always sanitise before wrapping so the on-chain payload is valid.
        $safe = self::sanitize($asset);
        return [
            '721' => [
                $policyId => [
                    $assetNameUtf8 => $safe,
                ],
            ],
        ];
    }

    // =========================================================================
    // Internal helpers
    // =========================================================================

    /**
     * Recursively find all JSON paths that contain string values > 64 bytes.
     *
     * @param array<mixed,mixed> $node
     * @return string[]  Dot-notation paths of offending fields
     */
    private static function findLongStrings(array $node, string $prefix = ''): array
    {
        $found = [];
        foreach ($node as $key => $value) {
            $path = $prefix !== '' ? "{$prefix}.{$key}" : (string) $key;
            if (is_string($value) && strlen($value) > self::MAX_STRING_BYTES) {
                $found[] = $path . ' (' . strlen($value) . 'B)';
            } elseif (is_array($value)) {
                $found = array_merge($found, self::findLongStrings($value, $path));
            }
        }
        return $found;
    }

    private static function isEmpty(mixed $v): bool
    {
        return $v === null || $v === '' || (is_array($v) && $v === []);
    }

    private static function joinIfArray(mixed $v): string
    {
        if (is_array($v)) {
            return implode('', array_map(static fn($x) => (string) $x, $v));
        }
        return (string) $v;
    }
}
