<?php
declare(strict_types=1);

/**
 * Static validation of the three Founders Block 88 seed SQL files.
 *
 * Cannot connect to a DB from the dev machine, so this file parses the
 * SQL text and asserts structural + cross-file consistency:
 *
 *   - Correct number of INSERT statements per file
 *   - Every referenced CNFT ID in the marketplace seed is present
 *     in the main-site stories seed under the correct item_num
 *   - Every per_item story HTML fallback file exists on disk
 *   - block88 / E101837 / batch 89 / scnft_founders / per_item all agree
 *
 * Run:  php tests/test_founders_seed_static.php
 */

$pass = 0; $fail = 0;

function t(string $name, callable $fn): void {
    global $pass, $fail;
    echo "• $name ... ";
    try { $fn(); $pass++; echo "ok\n"; }
    catch (Throwable $e) { $fail++; echo "FAIL — " . $e->getMessage() . "\n"; }
}

function mustRead(string $path): string {
    if (!is_file($path)) throw new RuntimeException("missing: $path");
    $s = file_get_contents($path);
    if ($s === false) throw new RuntimeException("unreadable: $path");
    return $s;
}

$marketplaceRoot = dirname(__DIR__);
$mainSiteRoot    = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . '01_rarefolio.io';

$tokensSql  = $marketplaceRoot . '/db/migrations/007_seed_founders_block88_tokens.sql';
$blocksSql  = $mainSiteRoot    . '/api/sql/seed_block88_blocks.sql';
$storiesSql = $mainSiteRoot    . '/api/sql/seed_block88_stories.sql';

$cnftIds = [
    'qd-silver-0000705','qd-silver-0000706','qd-silver-0000707','qd-silver-0000708',
    'qd-silver-0000709','qd-silver-0000710','qd-silver-0000711','qd-silver-0000712',
];

echo "Founders Block 88 — SQL static validation\n";
echo "=========================================\n";

t('all three SQL files exist and are non-empty', function () use ($tokensSql, $blocksSql, $storiesSql) {
    foreach ([$tokensSql, $blocksSql, $storiesSql] as $f) {
        $s = mustRead($f);
        if (strlen(trim($s)) < 100) throw new RuntimeException("$f is suspiciously short");
    }
});

t('tokens SQL has exactly 8 INSERT statements', function () use ($tokensSql) {
    $s = mustRead($tokensSql);
    $n = preg_match_all('/\bINSERT\s+INTO\s+qd_tokens\b/i', $s);
    if ($n !== 8) throw new RuntimeException("expected 8 INSERTs, found $n");
});

t('tokens SQL references every one of the 8 CNFT IDs', function () use ($tokensSql, $cnftIds) {
    $s = mustRead($tokensSql);
    foreach ($cnftIds as $id) {
        if (!str_contains($s, "'$id'")) throw new RuntimeException("missing id $id");
    }
});

t('tokens SQL marks all 8 as unminted / platform / none / secondary_eligible=1', function () use ($tokensSql) {
    $s = mustRead($tokensSql);
    // Each INSERT should contain this exact tuple once.
    $n = substr_count($s, "'platform', 'none', 'unminted', 1,");
    if ($n !== 8) throw new RuntimeException("expected 8 canonical state tuples, found $n");
});

t('tokens SQL has bar_serial=E101837 eight times (2 occurrences per row: top-level + nested attributes)', function () use ($tokensSql) {
    $s = mustRead($tokensSql);
    $n = preg_match_all("/'E101837'/", $s);
    if ($n < 16) throw new RuntimeException("expected >=16 occurrences of E101837, got $n");
});

t('tokens SQL edition pattern 1/8..8/8 present exactly once each', function () use ($tokensSql) {
    $s = mustRead($tokensSql);
    for ($i = 1; $i <= 8; $i++) {
        $n = substr_count($s, "'$i/8'");
        if ($n !== 2) throw new RuntimeException("edition $i/8 appears $n times; expected 2 (VALUES + attribute)");
        // Actually: only once in VALUES(...) for edition; once in the CIP-25 JSON. That's 2 per row.
    }
});

t('tokens SQL has 8 unique archetype names', function () use ($tokensSql) {
    $s = mustRead($tokensSql);
    $archetypes = ['Archivist','Cartographer','Sentinel','Artisan','Scholar','Ambassador','Mentor','Architect'];
    foreach ($archetypes as $a) {
        if (!str_contains($s, "'$a'")) throw new RuntimeException("missing archetype: $a");
    }
});

t('blocks SQL has exactly 1 INSERT for block88 / E101837 / batch 89', function () use ($blocksSql) {
    $s = mustRead($blocksSql);
    $n = preg_match_all('/\bINSERT\s+INTO\s+qd_blocks\b/i', $s);
    if ($n !== 1) throw new RuntimeException("expected 1 INSERT, found $n");
    if (!str_contains($s, "'block88'"))       throw new RuntimeException("missing block_id 'block88'");
    if (!str_contains($s, "'E101837'"))       throw new RuntimeException("missing bar_serial 'E101837'");
    if (!preg_match('/,\s*89\s*,/', $s))      throw new RuntimeException("missing batch_num 89");
    if (!str_contains($s, "'scnft_founders'"))throw new RuntimeException("missing folder_slug 'scnft_founders'");
    if (!str_contains($s, "'Founders'"))      throw new RuntimeException("missing label 'Founders'");
    if (!str_contains($s, "'per_item'"))      throw new RuntimeException("missing story_mode 'per_item'");
});

t('stories SQL has 9 INSERT statements (1 shared + 8 per-item)', function () use ($storiesSql) {
    $s = mustRead($storiesSql);
    $n = preg_match_all('/\bINSERT\s+INTO\s+qd_stories\b/i', $s);
    if ($n !== 9) throw new RuntimeException("expected 9 INSERTs, found $n");
});

t('stories SQL references item_num 1..8 exactly once each and NULL exactly once', function () use ($storiesSql) {
    $s = mustRead($storiesSql);
    $nullN = preg_match_all("/\('block88',\s*NULL,/", $s);
    if ($nullN !== 1) throw new RuntimeException("expected 1 NULL item_num (shared), got $nullN");
    for ($i = 1; $i <= 8; $i++) {
        $n = preg_match_all("/\('block88',\s*$i,/", $s);
        if ($n !== 1) throw new RuntimeException("expected 1 INSERT for item_num $i, got $n");
    }
});

t('stories SQL per-item rows mention the correct archetype in each', function () use ($storiesSql) {
    $s = mustRead($storiesSql);
    $expected = [
        1 => 'Archivist',
        2 => 'Cartographer',
        3 => 'Sentinel',
        4 => 'Artisan',
        5 => 'Scholar',
        6 => 'Ambassador',
        7 => 'Mentor',
        8 => 'Architect',
    ];
    foreach ($expected as $i => $archetype) {
        if (!preg_match("/\('block88',\s*$i,\s*[\s\S]{0,800}$archetype/", $s)) {
            throw new RuntimeException("item_num $i does not reference archetype $archetype");
        }
    }
});

t('every per_item story HTML fallback file exists', function () use ($mainSiteRoot, $cnftIds) {
    foreach ($cnftIds as $id) {
        $f = $mainSiteRoot . '/assets/stories/block88/' . $id . '.html';
        if (!is_file($f)) throw new RuntimeException("missing fallback: $f");
        $content = (string) file_get_contents($f);
        if (strlen($content) < 50) throw new RuntimeException("fallback too short: $f");
    }
});

t('shared.html fallback exists and contains "Founders"', function () use ($mainSiteRoot) {
    $f = $mainSiteRoot . '/assets/stories/block88/shared.html';
    if (!is_file($f)) throw new RuntimeException("missing: $f");
    $c = (string) file_get_contents($f);
    if (!str_contains($c, 'Founders')) throw new RuntimeException("missing 'Founders' text");
});

t('scnft_founders artwork directory exists (with README)', function () use ($mainSiteRoot) {
    $dir = $mainSiteRoot . '/assets/img/collection/scnft_founders';
    if (!is_dir($dir)) throw new RuntimeException("missing dir: $dir");
    if (!is_file($dir . '/README.md')) throw new RuntimeException("missing README.md");
});

t('cross-file: block_id/bar_serial/folder_slug consistent across all three SQLs', function () use ($tokensSql, $blocksSql, $storiesSql) {
    foreach ([$tokensSql, $blocksSql, $storiesSql] as $f) {
        $s = mustRead($f);
        // Marketplace SQL refers to collection_slug not block_id; check the slug contains "founders"
        $hasSlugOrBlock = str_contains($s, "'silverbar-01-founders'") || str_contains($s, "'block88'");
        if (!$hasSlugOrBlock) throw new RuntimeException("$f has neither silverbar-01-founders nor block88");
    }
    // Only blocks SQL carries E101837 + scnft_founders together
    $blocks = mustRead($blocksSql);
    if (!str_contains($blocks, "'E101837'") || !str_contains($blocks, "'scnft_founders'")) {
        throw new RuntimeException("blocks SQL must tie E101837 <-> scnft_founders");
    }
});

t('no accidental semicolons inside JSON strings', function () use ($tokensSql) {
    // The CIP-25 JSON uses JSON_OBJECT(...), so internal values contain quotes/commas
    // but should not close the statement prematurely. Count that the number of
    // top-level statement terminators matches the number of INSERTs.
    $s = mustRead($tokensSql);
    // Strip single-line comments
    $stripped = preg_replace('/^--.*$/m', '', $s);
    // Count top-level ; that follow a CURRENT_TIMESTAMP (the last field of each ON DUPLICATE KEY block)
    $n = preg_match_all('/updated_at\s*=\s*CURRENT_TIMESTAMP\s*;/', $stripped);
    if ($n !== 8) throw new RuntimeException("expected 8 statement terminators after CURRENT_TIMESTAMP, found $n");
});

echo "\nResults: $pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
