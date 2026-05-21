<?php
declare(strict_types=1);

namespace RareFolio\Cip25;

final class ImportRowParser
{
    /**
     * Parse an uploaded import CSV into preview rows using the same behavior as admin mint import.
     *
     * @return array{preview:array<int,array<string,mixed>>,parseError:?string}
     */
    public static function parseUploadedCsv(string $tmpPath): array
    {
        $handle = @fopen($tmpPath, 'r');
        if (!$handle) {
            return [
                'preview' => [],
                'parseError' => 'Could not open uploaded file.',
            ];
        }

        $headers = null;
        $preview = [];
        $lineNum = 0;

        while (($row = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
            $lineNum++;
            // Skip comment rows (first cell starts with #).
            if (str_starts_with(trim($row[0] ?? ''), '#')) {
                continue;
            }

            // First non-comment row is header.
            if ($headers === null) {
                $headers = array_map('trim', $row);
                continue;
            }

            if (count($row) < count($headers)) {
                $row = array_pad($row, count($headers), '');
            }

            $data = array_combine($headers, array_map('trim', $row));
            if (!$data) {
                continue;
            }

            // Skip fully blank rows.
            if (implode('', array_values($data)) === '') {
                continue;
            }

            $preview[] = self::parsePreviewRow($data, $lineNum);
        }

        fclose($handle);

        if ($headers === null) {
            return [
                'preview' => [],
                'parseError' => 'CSV appears to have no header row.',
            ];
        }

        return [
            'preview' => $preview,
            'parseError' => null,
        ];
    }
    /**
     * Parse one CSV data row into a validated preview entry.
     *
     * @param array<string,string> $data
     * @return array<string,mixed>
     */
    public static function parsePreviewRow(array $data, int $line): array
    {
        $tid         = $data['rarefolio_token_id'] ?? '';
        $collSlug    = $data['collection_slug'] ?? '';
        $policyId    = $data['policy_id'] ?? '';
        $assetName   = $data['asset_name_utf8'] ?? '';
        $title       = $data['title'] ?? '';
        $charName    = $data['character_name'] ?? '';
        $edition     = $data['edition'] ?? '';
        $artist      = $data['artist'] ?? '';
        $description = $data['description'] ?? '';
        $imageIpfs   = $data['image_ipfs'] ?? '';
        $mediaType   = $data['mediaType'] ?: 'image/jpeg';
        $website     = $data['website'] ?? '';

        // Build attributes from attr_* columns.
        $attributes = [];
        foreach ($data as $col => $val) {
            if (str_starts_with($col, 'attr_') && $val !== '') {
                $attributes[substr($col, 5)] = $val;
            }
        }

        // Build top-level custom metadata fields from meta_* columns.
        $customMeta = [];
        foreach ($data as $col => $val) {
            if (str_starts_with($col, 'meta_') && $val !== '') {
                $customMeta[substr($col, 5)] = $val;
            }
        }

        // Assemble the CIP-25 asset object.
        $asset = array_filter([
            'name'               => $title,
            'image'              => $imageIpfs,
            'mediaType'          => $mediaType,
            'description'        => $description !== '' ? $description : null,
            'artist'             => $artist,
            'edition'            => $edition,
            'attributes'         => !empty($attributes) ? $attributes : null,
            'rarefolio_token_id' => $tid,
            'collection'         => $collSlug,
            'website'            => $website !== '' ? $website : null,
        ] + $customMeta, static fn($v) => $v !== null && $v !== '');

        // Validate with strict byte-limit enforcement.
        $result = Validator::validate($asset, true);
        $errors = $result['errors'];
        $warnings = $result['warnings'];

        // Sanitize for storage compatibility after validation passes.
        $asset = Validator::sanitize($asset);

        // Extra import checks.
        if ($tid === '') {
            $errors[] = 'rarefolio_token_id is required.';
        }
        if ($assetName === '') {
            $errors[] = 'asset_name_utf8 is required.';
        }
        if ($collSlug === '') {
            $errors[] = 'collection_slug is required.';
        }
        if ($policyId !== '' && !preg_match('/^[0-9a-f]{56}$/i', $policyId)) {
            $errors[] = 'policy_id must be 56 hex chars (or left blank).';
        }

        $status = match (true) {
            !empty($errors) => 'error',
            !empty($warnings) => 'warning',
            default => 'ok',
        };

        return [
            'line' => $line,
            'rarefolio_token_id' => $tid,
            'collection_slug' => $collSlug,
            'policy_id' => $policyId,
            'asset_name_utf8' => $assetName,
            'title' => $title,
            'character_name' => $charName,
            'edition' => $edition,
            'image_ipfs' => $imageIpfs,
            'asset' => $asset,
            'errors' => $errors,
            'warnings' => $warnings,
            'status' => $status,
        ];
    }

    /**
     * @param array<string,mixed> $row
     * @return array{valid:bool,errors:array<int,string>,warnings:array<int,string>}
     */
    public static function validateConfirmedRow(array $row): array
    {
        $errors = [];
        $warnings = [];
        $asset = $row['asset'] ?? null;
        if (!is_array($asset)) {
            $errors[] = 'Invalid row payload: missing metadata asset object.';
        } else {
            $validation = Validator::validate($asset, true);
            $errors = array_merge($errors, $validation['errors']);
            $warnings = array_merge($warnings, $validation['warnings']);
        }

        $tid = trim((string)($row['rarefolio_token_id'] ?? ''));
        $assetName = trim((string)($row['asset_name_utf8'] ?? ''));
        $collSlug = trim((string)($row['collection_slug'] ?? ''));
        $policyId = trim((string)($row['policy_id'] ?? ''));

        if ($tid === '') {
            $errors[] = 'rarefolio_token_id is required.';
        }
        if ($assetName === '') {
            $errors[] = 'asset_name_utf8 is required.';
        }
        if ($collSlug === '') {
            $errors[] = 'collection_slug is required.';
        }
        if ($policyId !== '' && !preg_match('/^[0-9a-f]{56}$/i', $policyId)) {
            $errors[] = 'policy_id must be 56 hex chars (or left blank).';
        }

        return [
            'valid' => $errors === [],
            'errors' => $errors,
            'warnings' => $warnings,
        ];
    }
}
