<?php
/**
 * Phase 1 end-to-end verification.
 *
 *   php verify.php
 *
 * Checks what can be verified without a running DB, sidecar, or Blockfrost key:
 *   1. Every PHP file in src/ and admin/ has clean syntax.
 *   2. Every SQL migration file is non-empty and contains executable DDL or DML.
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

function runProcess(array $args, ?string $cwd = null): array
{
    $desc = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $options = PHP_OS_FAMILY === 'Windows' ? ['bypass_shell' => true] : [];
    $proc = @proc_open($args, $desc, $pipes, $cwd, null, $options);
    if (!is_resource($proc)) {
        return [
            'exit_code' => 127,
            'stdout' => '',
            'stderr' => 'failed to start process',
        ];
    }

    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($proc);

    return [
        'exit_code' => (int) $exitCode,
        'stdout' => is_string($stdout) ? $stdout : '',
        'stderr' => is_string($stderr) ? $stderr : '',
    ];
}

function httpGetJson(string $url, int $timeoutSeconds = 2): array
{
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeoutSeconds,
            CURLOPT_CONNECTTIMEOUT => $timeoutSeconds,
        ]);
        $bodyRaw = curl_exec($ch);
        if ($bodyRaw === false) {
            $error = curl_error($ch);
            curl_close($ch);
            return ['status' => 0, 'body' => null, 'raw' => '', 'error' => $error];
        }
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $bodyText = is_string($bodyRaw) ? $bodyRaw : '';
        $body = json_decode($bodyText, true);
        return [
            'status' => $status,
            'body' => is_array($body) ? $body : null,
            'raw' => $bodyText,
        ];
    }

    $curlBin = PHP_OS_FAMILY === 'Windows' ? 'curl.exe' : 'curl';
    $args = [
        $curlBin,
        '--silent',
        '--show-error',
        '--url', $url,
        '--connect-timeout', (string) $timeoutSeconds,
        '--max-time', (string) $timeoutSeconds,
        '--write-out', "\n__CURL_STATUS__:%{http_code}",
        '--header', 'Accept: application/json',
    ];
    $result = runProcess($args);
    $raw = (string) ($result['stdout'] ?? '');
    $stderr = trim((string) ($result['stderr'] ?? ''));
    $exitCode = (int) ($result['exit_code'] ?? 127);

    if (!preg_match('/__CURL_STATUS__:(\d{3})\s*$/', $raw, $m)) {
        return [
            'status' => 0,
            'body' => null,
            'raw' => $stderr !== '' ? trim($raw . "\n" . $stderr) : trim($raw),
            'error' => 'curl fallback did not return HTTP status (exit=' . $exitCode . ($stderr !== '' ? ', stderr=' . $stderr : '') . ')',
        ];
    }

    $status = (int) $m[1];
    $bodyRaw = preg_replace('/\n__CURL_STATUS__:\d{3}\s*$/', '', $raw);
    $body = is_string($bodyRaw) ? json_decode($bodyRaw, true) : null;

    return [
        'status' => $status,
        'body' => is_array($body) ? $body : null,
        'raw' => (string) $bodyRaw,
        'curl_exit' => $exitCode,
    ];
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
        $proc = runProcess([$phpBin, '-l', $f]);
        $out = trim(((string) ($proc['stdout'] ?? '')) . "\n" . ((string) ($proc['stderr'] ?? '')));
        if (($proc['exit_code'] ?? 1) !== 0 || strpos($out, 'No syntax errors') === false) {
            throw new RuntimeException("$f: $out");
        }
    }
});

// 2) migrations
step('Migrations: each file has executable SQL statements', function () use ($root) {
    $files = glob($root . '/db/migrations/*.sql');
    assertTrue(count($files) >= 6, 'expected >= 6 migrations, got ' . count($files));
    foreach ($files as $f) {
        $sql = file_get_contents($f);
        assertTrue(is_string($sql) && trim($sql) !== '', "$f is empty");
        $sqlNoBlockComments = preg_replace('#/\*.*?\*/#s', ' ', $sql);
        $sqlNoComments = preg_replace('/^\s*--.*$/m', ' ', (string) $sqlNoBlockComments);
        $normalized = strtoupper((string) $sqlNoComments);

        $hasMigrationStatement = preg_match(
            '/\b(CREATE\s+TABLE|ALTER\s+TABLE|CREATE\s+(?:UNIQUE\s+)?INDEX|DROP\s+TABLE|DROP\s+INDEX|INSERT\s+INTO|UPDATE\s+[A-Z0-9_`]+|DELETE\s+FROM)\b/',
            $normalized
        ) === 1;

        assertTrue($hasMigrationStatement, "$f does not appear to contain migration SQL statements");
    }
});

// 3) validator unit tests
step('CIP-25 Validator unit tests pass', function () use ($root) {
    $proc = runProcess([PHP_BINARY, $root . '/tests/test_cip25_validator.php']);
    if (($proc['exit_code'] ?? 1) !== 0) {
        $output = trim(((string) ($proc['stdout'] ?? '')) . "\n" . ((string) ($proc['stderr'] ?? '')));
        throw new RuntimeException("validator tests failed:\n" . $output);
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
    // Load env
    require_once $root . '/src/Config.php';
    \RareFolio\Config::load($root . '/.env');
    $base = \RareFolio\Config::get('SIDECAR_BASE_URL', 'http://localhost:4000');
    $resp = httpGetJson($base . '/health', 2);
    if (($resp['status'] ?? 0) !== 200) return 'skip';
    $j = $resp['body'] ?? null;
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
