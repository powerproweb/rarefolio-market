<?php
/**
 * Standalone tests for RareFolio\Cip25\Validator.
 * Run with:  php tests/test_cip25_validator.php
 *
 * No framework needed — just `assert()` and a pass/fail counter.
 */
declare(strict_types=1);

require_once __DIR__ . '/../src/Cip25/Validator.php';

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
        fwrite(STDOUT, "  FAIL $name — {$e->getMessage()}\n");
    }
}

function expect(bool $cond, string $msg = 'expectation failed'): void
{
    if (!$cond) {
        throw new RuntimeException($msg);
    }
}

fwrite(STDOUT, "CIP-25 Validator tests\n======================\n");

// ---- happy path ----
$goodAsset = [
    'name'               => 'Ember the Wanderer',
    'image'              => 'ipfs://bafybeigdyrzt5sfp7udm7hu76uh7y26nf3efuylqabf3oclgtqy55fbzdi',
    'mediaType'          => 'image/png',
    'description'        => 'A wandering ember in the hills.',
    'artist'             => 'RareFolio Studio',
    'edition'            => '3/50',
    'attributes'         => ['element' => 'fire', 'rarity' => 'rare'],
    'rarefolio_token_id' => 'RF-0001',
    'collection'         => 'genesis',
    'website'            => 'https://rarefolio.io/nft/ember',
];

test('happy-path: valid asset passes', function () use ($goodAsset) {
    $r = Validator::validate($goodAsset);
    expect($r['valid'] === true, 'expected valid=true, got ' . json_encode($r));
    expect($r['errors'] === [], 'expected no errors');
});

// ---- required fields ----
test('required: missing name fails', function () use ($goodAsset) {
    $bad = $goodAsset;
    unset($bad['name']);
    $r = Validator::validate($bad);
    expect($r['valid'] === false, 'should be invalid');
    expect(in_array('Missing required field: `name`', $r['errors'], true),
        'expected error about missing name');
});

test('required: missing rarefolio_token_id fails', function () use ($goodAsset) {
    $bad = $goodAsset;
    unset($bad['rarefolio_token_id']);
    $r = Validator::validate($bad);
    expect($r['valid'] === false);
    $found = false;
    foreach ($r['errors'] as $e) {
        if (str_contains($e, 'rarefolio_token_id')) {
            $found = true;
            break;
        }
    }
    expect($found, 'expected error mentioning rarefolio_token_id');
});

// ---- image validation ----
test('image: https-only URL is rejected', function () use ($goodAsset) {
    $bad = $goodAsset;
    $bad['image'] = 'https://example.com/foo.png';
    $r = Validator::validate($bad);
    expect($r['valid'] === false);
    $found = false;
    foreach ($r['errors'] as $e) {
        if (str_contains($e, 'ipfs://')) {
            $found = true;
            break;
        }
    }
    expect($found, 'expected error mentioning ipfs://');
});

test('image: ipfs:// URI passes', function () use ($goodAsset) {
    $r = Validator::validate($goodAsset);
    expect($r['valid'] === true);
});

// ---- rarefolio_token_id format ----
test('token_id: bad format is rejected', function () use ($goodAsset) {
    $bad = $goodAsset;
    $bad['rarefolio_token_id'] = 'rf-1';   // wrong case + too short
    $r = Validator::validate($bad);
    expect($r['valid'] === false);
});

test('token_id: RF-000001 (6 digits) is accepted', function () use ($goodAsset) {
    $ok = $goodAsset;
    $ok['rarefolio_token_id'] = 'RF-000001';
    $r = Validator::validate($ok);
    expect($r['valid'] === true, 'expected 6-digit token id to pass: ' . json_encode($r));
});

// ---- attributes shape ----
test('attributes: list instead of object is rejected', function () use ($goodAsset) {
    $bad = $goodAsset;
    $bad['attributes'] = ['a', 'b', 'c'];
    $r = Validator::validate($bad);
    expect($r['valid'] === false);
    $found = false;
    foreach ($r['errors'] as $e) {
        if (str_contains($e, 'attributes')) {
            $found = true;
            break;
        }
    }
    expect($found, 'expected error mentioning attributes shape');
});

// ---- description length warning (not error) ----
test('description: >64-char single line produces a warning', function () use ($goodAsset) {
    $a = $goodAsset;
    $a['description'] = str_repeat('x', 80);
    $r = Validator::validate($a);
    expect($r['valid'] === true, 'should still be valid, just warn');
    $found = false;
    foreach ($r['warnings'] as $w) {
        if (str_contains($w, '64 chars')) {
            $found = true;
            break;
        }
    }
    expect($found, 'expected a warning about >64 char line');
});

// ---- wrap() ----
test('wrap: produces correct 721 envelope', function () use ($goodAsset) {
    $w = Validator::wrap('deadbeef' . str_repeat('0', 48), 'RareFolioGenesis0001', $goodAsset);
    expect(isset($w['721']), 'missing 721 key');
    expect(count($w['721']) === 1, 'expected exactly one policy key');
    $inner = $w['721'][array_key_first($w['721'])];
    expect(isset($inner['RareFolioGenesis0001']), 'missing asset key');
    expect($inner['RareFolioGenesis0001']['rarefolio_token_id'] === 'RF-0001');
});

// ---- summary ----
fwrite(STDOUT, "\nResults: $pass passed, $fail failed\n");
if ($fail > 0) {
    fwrite(STDOUT, "\nFailed tests:\n");
    foreach ($failures as [$n, $m]) {
        fwrite(STDOUT, "  - $n: $m\n");
    }
    exit(1);
}
exit(0);
