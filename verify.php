<?php
/**
 * Phase 1 end-to-end verification.
 *
 *   php verify.php
 *
 * Checks what can be verified without a running DB, sidecar, or Blockfrost key:
 *   1. Every PHP file in src/ and admin/ has clean syntax.
 *   2. Every SQL migration file is non-empty and has a CREATE TABLE stmt.
 *   3. The CIP-25 validator unit tests pass.
 *   4. Every Node sidecar .ts file parses by TypeScript (best-effort: just checks file exists).
 *   5. The pre-sales CSV template has the expected 14 columns.
 *   6. Admin + sidecar dashboards have valid file layout.
 *
 * Optional (skipped if unreachable):
 *   7. If $SIDECAR_BASE_URL is reachable, hit /health.
 *   8. If DB env is set, try a PDO connection.
 */
declare(strict_types=1);

$root = __DIR__;
$pass = 0;
$fail = 0;
$skip = 0;
$issues = [];

function step(string $name, callable $fn): void
{
    global $pass, $fail, $skip, $issues;
    echo "• $name ... ";
    try {
        $r = $fn();
        if ($r === 'skip') {
            $skip++;
            echo "skip\n";
        } else {
            $pass++;
            echo "ok\n";
        }
    } catch (Throwable $e) {
        $fail++;
        $issues[] = "$name: " . $e->getMessage();
        echo "FAIL — {$e->getMessage()}\n";
    }
}

function assertTrue(bool $c, string $m): void
{
    if (!$c) throw new RuntimeException($m);
}

echo "RareFolio Phase 1 verification\n";
echo "==============================\n";

// 1) PHP syntax
step('PHP syntax: src/ + admin/ + db/ + tests/', function () use ($root) {
    $phpBin = PHP_BINARY;
    $files = [];
    foreach (['src', 'admin', 'db', 'tests'] as $dir) {
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root . DIRECTORY_SEPARATOR . $dir, RecursiveDirectoryIterator::SKIP_DOTS)
        );
        foreach ($it as $f) {
            if ($f->getExtension() === 'php') $files[] = $f->getPathname();
        }
    }
    assertTrue(count($files) > 0, 'no PHP files found to check');
    foreach ($files as $f) {
        $cmd = escapeshellarg($phpBin) . ' -l ' . escapeshellarg($f) . ' 2>&1';
        $out = shell_exec($cmd) ?? '';
        if (strpos($out, 'No syntax errors') === false) {
            throw new RuntimeException("$f: $out");
        }
    }
});

// 2) migrations
step('Migrations: each file has CREATE TABLE', function () use ($root) {
    $files = glob($root . '/db/migrations/*.sql');
    assertTrue(count($files) >= 6, 'expected >= 6 migrations, got ' . count($files));
    foreach ($files as $f) {
        $sql = file_get_contents($f);
        assertTrue(is_string($sql) && trim($sql) !== '', "$f is empty");
        assertTrue(stripos($sql, 'CREATE TABLE') !== false, "$f missing CREATE TABLE");
    }
});

// 3) validator unit tests
step('CIP-25 Validator unit tests pass', function () use ($root) {
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/tests/test_cip25_validator.php') . ' 2>&1';
    $out  = shell_exec($cmd) ?? '';
    $code = 0;
    exec($cmd, $lines, $code);
    if ($code !== 0) {
        throw new RuntimeException("validator tests failed:\n" . implode("\n", $lines));
    }
});

// 4) sidecar files exist
step('Sidecar TypeScript skeleton exists', function () use ($root) {
    foreach ([
        '/sidecar/package.json',
        '/sidecar/tsconfig.json',
        '/sidecar/src/index.ts',
        '/sidecar/src/routes/mint.ts',
        '/sidecar/src/routes/asset.ts',
        '/sidecar/src/routes/handle.ts',
        '/sidecar/src/lib/blockfrost.ts',
    ] as $rel) {
        assertTrue(is_file($root . $rel), "missing $rel");
    }
});

// 5) CSV template columns
step('qd_presales_template.csv has expected 14 columns', function () use ($root) {
    $f = $root . '/qd_presales_template.csv';
    assertTrue(is_file($f), 'missing qd_presales_template.csv');
    $header = fgetcsv(fopen($f, 'r'), 0, ',', '"', '\\');
    assertTrue(is_array($header), 'could not read header');
    $expected = [
        'rarefolio_token_id','policy_id','asset_name_hex','asset_fingerprint','character_name',
        'edition','buyer_wallet_addr','buyer_email','buyer_name','sale_price_ada','sale_date',
        'mint_tx_hash','gift_flag','notes',
    ];
    assertTrue($header === $expected,
        "columns mismatch.\n  got:      " . implode(',', $header) .
        "\n  expected: " . implode(',', $expected));
});

// 6) admin pages exist
step('Admin dashboard pages present', function () use ($root) {
    foreach ([
        '/admin/index.php',
        '/admin/mint.php',
        '/admin/mint-new.php',
        '/admin/mint-detail.php',
        '/admin/mint-action.php',
        '/admin/mint-validate.php',
        '/admin/asset-lookup.php',
        '/admin/includes/bootstrap.php',
        '/admin/includes/header.php',
        '/admin/includes/footer.php',
        '/assets/admin.css',
    ] as $rel) {
        assertTrue(is_file($root . $rel), "missing $rel");
    }
});

// 7) sidecar liveness (optional)
step('Sidecar /health (optional)', function () use ($root) {
    if (!file_exists($root . '/.env')) return 'skip';
    if (!function_exists('curl_init')) return 'skip';
    // Load env
    require_once $root . '/src/Config.php';
    \RareFolio\Config::load($root . '/.env');
    $base = \RareFolio\Config::get('SIDECAR_BASE_URL', 'http://localhost:4000');
    $ch = curl_init($base . '/health');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 2,
        CURLOPT_CONNECTTIMEOUT => 2,
    ]);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code !== 200) return 'skip';
    $j = json_decode((string)$body, true);
    assertTrue(is_array($j) && ($j['ok'] ?? false) === true, 'sidecar health returned non-ok');
});

// 8) DB connection (optional)
step('DB connection (optional)', function () use ($root) {
    if (!file_exists($root . '/.env')) return 'skip';
    require_once $root . '/src/Config.php';
    require_once $root . '/src/Db.php';
    \RareFolio\Config::load($root . '/.env');
    if (!\RareFolio\Config::get('DB_NAME') || !\RareFolio\Config::get('DB_USER')) return 'skip';
    try {
        $pdo = \RareFolio\Db::pdo();
        $pdo->query('SELECT 1')->fetchColumn();
    } catch (Throwable $e) {
        return 'skip';
    }
});

echo "\nResults: $pass passed, $fail failed, $skip skipped\n";
if ($fail > 0) {
    echo "\nIssues:\n";
    foreach ($issues as $i) echo "  - $i\n";
    exit(1);
}
exit(0);
