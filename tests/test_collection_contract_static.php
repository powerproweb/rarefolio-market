<?php
declare(strict_types=1);

/**
 * Config-driven static validator for collection launch contracts.
 *
 * Usage:
 *   php tests/test_collection_contract_static.php
 *   php tests/test_collection_contract_static.php --config=tests/collection-contracts/founders-block88.json
 */

$defaultConfig = __DIR__ . '/collection-contracts/founders-block88.json';
$configArg = null;
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--config=(.+)$/', (string) $arg, $m) === 1) {
        $configArg = (string) $m[1];
    }
}
$configPath = $configArg !== null ? $configArg : $defaultConfig;

if (!preg_match('#^(?:[A-Za-z]:[\\\\/]|/)#', $configPath)) {
    $configPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . ltrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $configPath), DIRECTORY_SEPARATOR);
}
if (!is_file($configPath)) {
    fwrite(STDERR, "Missing config: {$configPath}\n");
    exit(1);
}

$rawConfig = file_get_contents($configPath);
$config = is_string($rawConfig) ? json_decode($rawConfig, true) : null;
if (!is_array($config)) {
    fwrite(STDERR, "Invalid JSON config: {$configPath}\n");
    exit(1);
}

/** @var array<string,mixed> $config */
$marketRoot = dirname(__DIR__);
$mainSiteRoot = resolvePath((string) ($config['main_site_root'] ?? ''), $marketRoot);

$tokenSeedPath = resolvePath((string) ($config['token_seed_sql'] ?? ''), $marketRoot);
$blockSeedPath = resolvePath((string) ($config['block_seed_sql'] ?? ''), $mainSiteRoot);
$storiesSeedPath = resolvePath((string) ($config['stories_seed_sql'] ?? ''), $mainSiteRoot);
$storyFallbackDir = resolvePath((string) ($config['story_fallback_dir'] ?? ''), $mainSiteRoot);
$sharedFallbackPath = resolvePath((string) ($config['shared_fallback_file'] ?? ''), $mainSiteRoot);
$assetDir = resolvePath((string) ($config['asset_dir'] ?? ''), $mainSiteRoot);

$expected = is_array($config['expected'] ?? null) ? $config['expected'] : [];
$tokenIds = is_array($config['token_ids'] ?? null) ? array_values(array_filter($config['token_ids'], 'is_string')) : [];
$assetRequiredFiles = is_array($config['asset_required_files'] ?? null) ? array_values(array_filter($config['asset_required_files'], 'is_string')) : [];

$pass = 0;
$fail = 0;

function t(string $name, callable $fn): void
{
    global $pass, $fail;
    echo "• {$name} ... ";
    try {
        $fn();
        $pass++;
        echo "ok\n";
    } catch (Throwable $e) {
        $fail++;
        echo "FAIL - " . $e->getMessage() . "\n";
    }
}

function mustRead(string $path): string
{
    if (!is_file($path)) {
        throw new RuntimeException("missing: {$path}");
    }
    $content = file_get_contents($path);
    if (!is_string($content) || trim($content) === '') {
        throw new RuntimeException("unreadable or empty: {$path}");
    }
    return $content;
}

function resolvePath(string $path, string $base): string
{
    $path = trim($path);
    if ($path === '') {
        return '';
    }
    if (preg_match('#^(?:[A-Za-z]:[\\\\/]|/)#', $path) === 1) {
        return str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
    }
    return $base . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
}

function assertContainsAll(string $content, array $needles, string $label): void
{
    foreach ($needles as $needle) {
        if (!str_contains($content, (string) $needle)) {
            throw new RuntimeException("{$label} missing expected value: {$needle}");
        }
    }
}

echo (($config['name'] ?? 'Collection') . " - static contract validation\n");
echo "================================================\n";

t('contract config paths resolve', function () use ($tokenSeedPath, $blockSeedPath, $storiesSeedPath, $storyFallbackDir, $sharedFallbackPath, $assetDir): void {
    foreach ([$tokenSeedPath, $blockSeedPath, $storiesSeedPath, $sharedFallbackPath] as $path) {
        if ($path === '') {
            throw new RuntimeException('required config path is empty');
        }
    }
    if ($storyFallbackDir === '' || $assetDir === '') {
        throw new RuntimeException('required directory path is empty');
    }
});

t('seed SQL files exist and are readable', function () use ($tokenSeedPath, $blockSeedPath, $storiesSeedPath): void {
    foreach ([$tokenSeedPath, $blockSeedPath, $storiesSeedPath] as $path) {
        mustRead($path);
    }
});

t('token seed insert count matches expected', function () use ($tokenSeedPath, $expected): void {
    $sql = mustRead($tokenSeedPath);
    $table = (string) ($expected['token_insert_table'] ?? 'qd_tokens');
    $insertCount = (int) ($expected['token_insert_count'] ?? 0);
    if ($insertCount <= 0) {
        throw new RuntimeException('expected.token_insert_count must be > 0');
    }
    $actual = preg_match_all('/\bINSERT\s+INTO\s+' . preg_quote($table, '/') . '\b/i', $sql);
    if ($actual !== $insertCount) {
        throw new RuntimeException("expected {$insertCount} INSERTs into {$table}, found {$actual}");
    }
});

t('token IDs are present in token seed SQL', function () use ($tokenSeedPath, $tokenIds): void {
    if ($tokenIds === []) {
        throw new RuntimeException('token_ids is empty');
    }
    $sql = mustRead($tokenSeedPath);
    foreach ($tokenIds as $tokenId) {
        if (!str_contains($sql, "'" . $tokenId . "'")) {
            throw new RuntimeException("missing token id in seed SQL: {$tokenId}");
        }
    }
});

t('block seed contract values match expected', function () use ($blockSeedPath, $expected): void {
    $sql = mustRead($blockSeedPath);
    $table = (string) ($expected['block_insert_table'] ?? 'qd_blocks');
    $insertCount = (int) ($expected['block_insert_count'] ?? 1);
    $actual = preg_match_all('/\bINSERT\s+INTO\s+' . preg_quote($table, '/') . '\b/i', $sql);
    if ($actual !== $insertCount) {
        throw new RuntimeException("expected {$insertCount} INSERTs into {$table}, found {$actual}");
    }

    $needles = array_filter([
        (string) ($expected['block_id'] ?? ''),
        (string) ($expected['bar_serial'] ?? ''),
        (string) ($expected['folder_slug'] ?? ''),
        (string) ($expected['story_mode'] ?? ''),
    ], static fn(string $v): bool => $v !== '');
    assertContainsAll($sql, array_map(static fn(string $v): string => "'{$v}'", $needles), 'block seed');

    $batchNum = (int) ($expected['batch_num'] ?? 0);
    if ($batchNum > 0 && preg_match('/,\s*' . preg_quote((string) $batchNum, '/') . '\s*,/', $sql) !== 1) {
        throw new RuntimeException("block seed missing batch_num {$batchNum}");
    }
});

t('stories seed insert count and item coverage match expected', function () use ($storiesSeedPath, $expected, $tokenIds): void {
    $sql = mustRead($storiesSeedPath);
    $table = (string) ($expected['story_insert_table'] ?? 'qd_stories');
    $insertCount = (int) ($expected['story_insert_count'] ?? 0);
    if ($insertCount <= 0) {
        throw new RuntimeException('expected.story_insert_count must be > 0');
    }
    $actual = preg_match_all('/\bINSERT\s+INTO\s+' . preg_quote($table, '/') . '\b/i', $sql);
    if ($actual !== $insertCount) {
        throw new RuntimeException("expected {$insertCount} INSERTs into {$table}, found {$actual}");
    }

    $blockId = (string) ($expected['block_id'] ?? '');
    if ($blockId === '') {
        throw new RuntimeException('expected.block_id is required');
    }

    $sharedCount = preg_match_all("/\\('{$blockId}',\\s*NULL,/", $sql);
    if ($sharedCount !== 1) {
        throw new RuntimeException("expected exactly one shared story row for {$blockId}, found {$sharedCount}");
    }

    $itemCount = count($tokenIds);
    for ($i = 1; $i <= $itemCount; $i++) {
        $n = preg_match_all("/\\('{$blockId}',\\s*{$i},/", $sql);
        if ($n !== 1) {
            throw new RuntimeException("expected one per-item story row for item {$i}, found {$n}");
        }
    }
});

t('story fallback files exist for each token and shared page', function () use ($storyFallbackDir, $sharedFallbackPath, $tokenIds): void {
    if (!is_dir($storyFallbackDir)) {
        throw new RuntimeException("missing story fallback directory: {$storyFallbackDir}");
    }
    mustRead($sharedFallbackPath);
    foreach ($tokenIds as $tokenId) {
        $path = $storyFallbackDir . DIRECTORY_SEPARATOR . $tokenId . '.html';
        mustRead($path);
    }
});

t('asset directory exists with required files', function () use ($assetDir, $assetRequiredFiles): void {
    if (!is_dir($assetDir)) {
        throw new RuntimeException("missing asset directory: {$assetDir}");
    }
    foreach ($assetRequiredFiles as $fileName) {
        mustRead($assetDir . DIRECTORY_SEPARATOR . $fileName);
    }
});

echo "\nResults: {$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
