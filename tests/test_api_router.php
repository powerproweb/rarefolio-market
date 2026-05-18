<?php
declare(strict_types=1);

/**
 * Smoke test: start PHP's built-in server with the marketplace root as
 * document root and hit a few API endpoints to confirm the router and
 * response envelope are working correctly.
 *
 * Runs without a DB — only exercises /health, /, and a malformed
 * tokens/{id} to verify the validator.
 *
 * Run:  php tests/test_api_router.php
 */

$root = dirname(__DIR__);
$port = (int) (getenv('TEST_PORT') ?: 18765);

// Start PHP's built-in server with a router that dispatches /api/v1/* to
// the front controller (the real .htaccess is not honored by php -S).
$router = __DIR__ . DIRECTORY_SEPARATOR . 'cli_router.php';
$cmd = sprintf(
    '%s -S 127.0.0.1:%d -t %s %s',
    escapeshellarg(PHP_BINARY),
    $port,
    escapeshellarg($root),
    escapeshellarg($router)
);

$desc = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
$proc = proc_open($cmd, $desc, $pipes);
if (!is_resource($proc)) {
    fwrite(STDERR, "failed to start php server\n");
    exit(2);
}
stream_set_blocking($pipes[1], false);
stream_set_blocking($pipes[2], false);

// Give the server a moment to warm up
usleep(500_000);

$base = "http://127.0.0.1:$port";
$pass = 0; $fail = 0;

function t(string $name, callable $fn): void {
    global $pass, $fail;
    echo "• $name ... ";
    try { $fn(); $pass++; echo "ok\n"; }
    catch (Throwable $e) { $fail++; echo "FAIL — " . $e->getMessage() . "\n"; }
}

function httpGet(string $url): array {
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT        => 5,
            CURLOPT_HTTPGET        => true,
        ]);
        $raw = curl_exec($ch);
        if ($raw === false) {
            curl_close($ch);
            return ['status' => 0, 'body' => ''];
        }
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $body = substr((string) $raw, $headerSize);
        curl_close($ch);
        return ['status' => $code, 'body' => $body];
    }
    $ctx = stream_context_create([
        'http' => [
            'method'        => 'GET',
            'ignore_errors' => true,
            'timeout'       => 5,
        ],
    ]);
    $body = @file_get_contents($url, false, $ctx);
    $code = 0;
    if (!empty($http_response_header)) {
        foreach ($http_response_header as $h) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) {
                $code = (int) $m[1];
            }
        }
    }
    return ['status' => $code, 'body' => is_string($body) ? $body : ''];
}

echo "API router smoke tests\n======================\n";

t('GET /api/v1/health returns ok envelope', function () use ($base) {
    $r = httpGet("$base/api/v1/health");
    if ($r['status'] !== 200) throw new RuntimeException("status {$r['status']}");
    $j = json_decode($r['body'], true);
    if (!is_array($j) || ($j['ok'] ?? false) !== true) {
        throw new RuntimeException('unexpected body: ' . $r['body']);
    }
    if (($j['data']['service'] ?? '') !== 'rarefolio-marketplace-api') {
        throw new RuntimeException('missing service name');
    }
});

t('GET /api/v1 lists endpoints', function () use ($base) {
    $r = httpGet("$base/api/v1/");
    if ($r['status'] !== 200) throw new RuntimeException("status {$r['status']}");
    $j = json_decode($r['body'], true);
    if (!is_array($j) || empty($j['data']['endpoints'])) {
        throw new RuntimeException('missing endpoints list');
    }
});

t('GET /api/v1/tokens/bogus returns 400 bad_request', function () use ($base) {
    $r = httpGet("$base/api/v1/tokens/not-a-valid-id!!");
    if ($r['status'] !== 400) throw new RuntimeException("expected 400 got {$r['status']}");
    $j = json_decode($r['body'], true);
    if (!is_array($j) || ($j['ok'] ?? true) !== false) {
        throw new RuntimeException('expected error envelope');
    }
    if (($j['error']['code'] ?? '') !== 'bad_request') {
        throw new RuntimeException('expected bad_request code');
    }
});

t('GET /api/v1/does-not-exist returns 404', function () use ($base) {
    $r = httpGet("$base/api/v1/does-not-exist");
    if ($r['status'] !== 404) throw new RuntimeException("expected 404 got {$r['status']}");
});

// Shutdown
proc_terminate($proc);
fclose($pipes[0]); fclose($pipes[1]); fclose($pipes[2]);
proc_close($proc);

echo "\nResults: $pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
