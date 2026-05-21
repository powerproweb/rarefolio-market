<?php
/**
 * End-to-end style strict validation tests for mint import row flows.
 * Run with: php tests/test_mint_import_strict.php
 */
declare(strict_types=1);

require_once __DIR__ . '/../src/Cip25/Validator.php';
require_once __DIR__ . '/../src/Cip25/ImportRowParser.php';

use RareFolio\Cip25\ImportRowParser;
use RareFolio\Cip25\Validator;

$pass = 0;
$fail = 0;
$failures = [];

function test(string $name, callable $fn): void
{
    global $pass, $fail, $failures;
    try {
        $fn();
        $pass++;
        fwrite(STDOUT, "  ok   $name\n");
    } catch (Throwable $e) {
        $fail++;
        $failures[] = [$name, $e->getMessage()];
        fwrite(STDOUT, "  FAIL $name - {$e->getMessage()}\n");
    }
}

function expect(bool $cond, string $msg = 'expectation failed'): void
{
    if (!$cond) {
        throw new RuntimeException($msg);
    }
}

function hasNeedle(array $items, string $needle): bool
{
    foreach ($items as $item) {
        if (is_string($item) && str_contains($item, $needle)) {
            return true;
        }
    }
    return false;
}

function csvHeaderColumns(): array
{
    return [
        'rarefolio_token_id','collection_slug','policy_id','asset_name_utf8',
        'title','character_name','edition','artist','description',
        'image_ipfs','mediaType','website',
        'attr_bar_serial','attr_block','meta_certification',
    ];
}

/**
 * @param array<int,array<int,string>> $rows
 */
function writeTempCsv(array $rows): string
{
    $path = tempnam(sys_get_temp_dir(), 'rf_mint_import_');
    if ($path === false) {
        throw new RuntimeException('failed to create temp csv');
    }
    $h = fopen($path, 'w');
    if ($h === false) {
        throw new RuntimeException('failed to open temp csv');
    }
    foreach ($rows as $row) {
        fputcsv($h, $row, ',', '"', '\\');
    }
    fclose($h);
    return $path;
}

function baseImportRow(): array
{
    return [
        'rarefolio_token_id' => 'qd-silver-0000705',
        'collection_slug' => 'silverbar-01-founders',
        'policy_id' => '',
        'asset_name_utf8' => 'qd-silver-0000705',
        'title' => 'Founders #1',
        'character_name' => 'The Archivist',
        'edition' => '1/8',
        'artist' => 'RareFolio',
        'description' => 'Founder token for Block 88.',
        'image_ipfs' => 'ipfs://QmYwAPJzv5CZsnAzt8auVTL6f1d2E3F4G5H6J7K8L9M',
        'mediaType' => 'image/jpeg',
        'website' => 'https://rarefolio.io',
        'attr_bar_serial' => 'E101837',
        'attr_block' => '88',
        'meta_certification' => 'on-chain',
    ];
}

fwrite(STDOUT, "Mint import strict validation tests\n===================================\n");

test('preview: long description in CSV row is rejected', function (): void {
    $row = baseImportRow();
    $row['description'] = str_repeat('x', 65);

    $parsed = ImportRowParser::parsePreviewRow($row, 2);
    expect($parsed['status'] === 'error', 'expected error status');
    expect(hasNeedle($parsed['errors'], '64-byte Cardano metadata limit'), 'expected strict 64-byte error');
});

test('preview: long attr_* field is rejected', function (): void {
    $row = baseImportRow();
    $row['attr_story'] = str_repeat('y', 80);

    $parsed = ImportRowParser::parsePreviewRow($row, 3);
    expect($parsed['status'] === 'error', 'expected error status');
    expect(hasNeedle($parsed['errors'], '64-byte Cardano metadata limit'), 'expected strict 64-byte error');
});

test('preview: long meta_* field is rejected', function (): void {
    $row = baseImportRow();
    $row['meta_provenance'] = str_repeat('z', 90);

    $parsed = ImportRowParser::parsePreviewRow($row, 4);
    expect($parsed['status'] === 'error', 'expected error status');
    expect(hasNeedle($parsed['errors'], '64-byte Cardano metadata limit'), 'expected strict 64-byte error');
});

test('preview: valid CSV row remains importable', function (): void {
    $row = baseImportRow();
    $parsed = ImportRowParser::parsePreviewRow($row, 5);
    expect($parsed['status'] === 'ok', 'expected ok status for valid row');
    expect($parsed['errors'] === [], 'expected no errors');
    expect(isset($parsed['asset']['attributes']['bar_serial']), 'expected attr_* mapping');
    expect(($parsed['asset']['certification'] ?? null) === 'on-chain', 'expected meta_* mapping');
});

test('uploaded csv: preview parser handles comments, blanks, and strict errors', function (): void {
    $valid = baseImportRow();
    $invalid = baseImportRow();
    $invalid['rarefolio_token_id'] = 'qd-silver-0000706';
    $invalid['asset_name_utf8'] = 'qd-silver-0000706';
    $invalid['description'] = str_repeat('x', 72);

    $rows = [];
    $rows[] = csvHeaderColumns();
    $rows[] = ['# template notes', '', '', '', '', '', '', '', '', '', '', '', '', '', ''];
    $rows[] = array_values($valid);
    $rows[] = array_values($invalid);
    $rows[] = ['', '', '', '', '', '', '', '', '', '', '', '', '', '', ''];

    $csvPath = writeTempCsv($rows);
    try {
        $parsed = ImportRowParser::parseUploadedCsv($csvPath);
    } finally {
        @unlink($csvPath);
    }

    expect($parsed['parseError'] === null, 'expected no parse error');
    expect(count($parsed['preview']) === 2, 'expected exactly two parsed data rows');
    expect($parsed['preview'][0]['status'] === 'ok', 'expected first data row to be valid');
    expect($parsed['preview'][1]['status'] === 'error', 'expected second data row to fail strict validation');
    expect(hasNeedle($parsed['preview'][1]['errors'], '64-byte Cardano metadata limit'), 'expected strict byte limit error in second row');
});

test('uploaded csv: empty file returns no-header parse error', function (): void {
    $csvPath = writeTempCsv([]);
    try {
        $parsed = ImportRowParser::parseUploadedCsv($csvPath);
    } finally {
        @unlink($csvPath);
    }
    expect($parsed['parseError'] === 'CSV appears to have no header row.', 'expected no-header parse error');
    expect($parsed['preview'] === [], 'expected no preview rows');
});

test('confirm: tampered posted asset with long string is rejected', function (): void {
    $row = baseImportRow();
    $parsed = ImportRowParser::parsePreviewRow($row, 6);
    expect($parsed['status'] === 'ok', 'expected valid preview before tampering');

    $tampered = $parsed;
    $tampered['asset']['description'] = str_repeat('t', 75);
    $validation = ImportRowParser::validateConfirmedRow($tampered);
    expect($validation['valid'] === false, 'expected strict confirm validation failure');
    expect(hasNeedle($validation['errors'], '64-byte Cardano metadata limit'), 'expected strict 64-byte error');
});

test('confirm: missing asset payload is rejected', function (): void {
    $row = baseImportRow();
    $validation = ImportRowParser::validateConfirmedRow($row);
    expect($validation['valid'] === false, 'expected confirm validation failure');
    expect(hasNeedle($validation['errors'], 'missing metadata asset object'), 'expected missing asset payload error');
});

test('confirm: missing asset_name_utf8 is rejected', function (): void {
    $row = baseImportRow();
    $parsed = ImportRowParser::parsePreviewRow($row, 8);
    expect($parsed['status'] === 'ok', 'expected valid preview');

    $tampered = $parsed;
    $tampered['asset_name_utf8'] = '';
    $validation = ImportRowParser::validateConfirmedRow($tampered);
    expect($validation['valid'] === false, 'expected confirm validation failure');
    expect(hasNeedle($validation['errors'], 'asset_name_utf8 is required.'), 'expected missing asset_name_utf8 error');
});

test('confirm: invalid policy id is rejected', function (): void {
    $row = baseImportRow();
    $parsed = ImportRowParser::parsePreviewRow($row, 9);
    expect($parsed['status'] === 'ok', 'expected valid preview');

    $tampered = $parsed;
    $tampered['policy_id'] = 'xyz-not-policy';
    $validation = ImportRowParser::validateConfirmedRow($tampered);
    expect($validation['valid'] === false, 'expected confirm validation failure');
    expect(hasNeedle($validation['errors'], 'policy_id must be 56 hex chars'), 'expected invalid policy id error');
});

test('confirm: chunked payload from sanitize() is accepted', function (): void {
    $row = baseImportRow();
    $parsed = ImportRowParser::parsePreviewRow($row, 7);
    expect($parsed['status'] === 'ok', 'expected valid preview');

    $safe = $parsed;
    $safe['asset']['description'] = Validator::sanitizeValue(str_repeat('s', 130));
    $validation = ImportRowParser::validateConfirmedRow($safe);
    expect($validation['valid'] === true, 'expected strict confirm validation pass for chunked strings');
});

fwrite(STDOUT, "\nResults: $pass passed, $fail failed\n");
if ($fail > 0) {
    fwrite(STDOUT, "\nFailed tests:\n");
    foreach ($failures as [$n, $m]) {
        fwrite(STDOUT, "  - $n: $m\n");
    }
    exit(1);
}
exit(0);