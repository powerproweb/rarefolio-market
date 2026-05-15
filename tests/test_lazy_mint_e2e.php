<?php
declare(strict_types=1);

/**
 * End-to-end smoke test for lazy-mint API behavior.
 *
 * Runs the local API server via tests/cli_router.php, then verifies:
 * - token status includes mint_mode
 * - listings payload includes status.mint_mode on returned rows
 * - buy-order guard paths still return expected non-destructive errors
 *
 * Run: php tests/test_lazy_mint_e2e.php
 */

$root = dirname(__DIR__);
$port = (int) (getenv('TEST_PORT') ?: 18766);
$externalBase = trim((string) (getenv('E2E_BASE_URL') ?: ''));
$insecureTls = trim((string) (getenv('E2E_INSECURE_TLS') ?: '')) === '1';

$proc = null;
$pipes = [];

if ($externalBase !== '') {
    $base = rtrim($externalBase, '/');
} else {
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
    usleep(500_000);

    $base = "http://127.0.0.1:$port";
}
$pass = 0;
$fail = 0;

function t(string $name, callable $fn): void
{
    global $pass, $fail;
    echo "• $name ... ";
    try {
        $fn();
        $pass++;
        echo "ok\n";
    } catch (Throwable $e) {
        $fail++;
        echo "FAIL — " . $e->getMessage() . "\n";
    }
}

function httpReq(string $method, string $url, ?array $jsonBody = null): array
{
    global $insecureTls;
    $method = strtoupper($method);

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        $headers = [];
        $payload = null;
        if ($jsonBody !== null) {
            $payload = json_encode($jsonBody, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $headers[] = 'Content-Type: application/json';
            $headers[] = 'Accept: application/json';
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT        => 12,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_POSTFIELDS     => $payload,
        ]);
        if ($insecureTls) {
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        }
        $raw = curl_exec($ch);
        if ($raw === false) {
            $err = curl_error($ch);
            curl_close($ch);
            return ['status' => 0, 'body' => null, 'raw' => '', 'error' => $err];
        }
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $bodyRaw = substr((string) $raw, $headerSize);
        curl_close($ch);
        $body = json_decode($bodyRaw, true);
        return ['status' => $code, 'body' => $body, 'raw' => $bodyRaw];
    }

    $headers = [];
    $content = '';
    if ($jsonBody !== null) {
        $content = json_encode($jsonBody, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '';
        $headers[] = 'Content-Type: application/json';
        $headers[] = 'Accept: application/json';
    }
    $ctx = stream_context_create([
        'http' => [
            'method'        => $method,
            'ignore_errors' => true,
            'timeout'       => 12,
            'header'        => implode("\r\n", $headers),
            'content'       => $content,
        ],
        'ssl' => [
            'verify_peer'      => !$insecureTls,
            'verify_peer_name' => !$insecureTls,
            'allow_self_signed'=> $insecureTls,
        ],
    ]);
    $bodyRaw = @file_get_contents($url, false, $ctx);
    $code = 0;
    if (!empty($http_response_header)) {
        foreach ($http_response_header as $h) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) {
                $code = (int) $m[1];
            }
        }
    }
    $bodyText = is_string($bodyRaw) ? $bodyRaw : '';
    $body = json_decode($bodyText, true);
    return ['status' => $code, 'body' => $body, 'raw' => $bodyText];
}

function requireOkEnvelope(array $resp, string $ctx): array
{
    if (($resp['status'] ?? 0) !== 200) {
        throw new RuntimeException("$ctx unexpected status " . ($resp['status'] ?? 0));
    }
    if (!is_array($resp['body'])) {
        throw new RuntimeException("$ctx did not return JSON body");
    }
    if (($resp['body']['ok'] ?? null) !== true) {
        throw new RuntimeException("$ctx did not return ok=true envelope");
    }
    return $resp['body'];
}

echo "Lazy-mint E2E smoke tests\n=========================\n";

$sampleToken = 'qd-silver-0000706';

t('GET /api/v1/health returns ok=true', function () use ($base): void {
    $resp = httpReq('GET', "$base/api/v1/health");
    $body = requireOkEnvelope($resp, '/api/v1/health');
    if (($body['data']['service'] ?? '') !== 'rarefolio-marketplace-api') {
        throw new RuntimeException('unexpected service value');
    }
});

t('GET /api/v1/tokens/{id} includes status.mint_mode', function () use ($base, $sampleToken): void {
    $resp = httpReq('GET', "$base/api/v1/tokens/$sampleToken");
    $body = requireOkEnvelope($resp, "/api/v1/tokens/$sampleToken");
    $status = $body['data']['status'] ?? null;
    if (!is_array($status)) {
        throw new RuntimeException('missing status object');
    }
    if (!array_key_exists('mint_mode', $status)) {
        throw new RuntimeException('status.mint_mode missing');
    }
    $mode = (string) ($status['mint_mode'] ?? '');
    if (!in_array($mode, ['on_demand', 'pre_minted'], true)) {
        throw new RuntimeException("invalid mint_mode: $mode");
    }
});

t('GET /api/v1/listings returns listings[] schema with status.mint_mode when rows exist', function () use ($base): void {
    $resp = httpReq('GET', "$base/api/v1/listings?limit=50");
    $body = requireOkEnvelope($resp, '/api/v1/listings');
    $listings = $body['data']['listings'] ?? null;
    if (!is_array($listings)) {
        throw new RuntimeException('data.listings is not an array');
    }

    foreach ($listings as $idx => $row) {
        if (!is_array($row)) {
            throw new RuntimeException("listing row #$idx is not an object");
        }
        $status = $row['status'] ?? null;
        if (!is_array($status)) {
            throw new RuntimeException("listing row #$idx missing status object");
        }
        if (!array_key_exists('mint_mode', $status)) {
            throw new RuntimeException("listing row #$idx missing status.mint_mode");
        }
        $mode = (string) ($status['mint_mode'] ?? '');
        if (!in_array($mode, ['on_demand', 'pre_minted'], true)) {
            throw new RuntimeException("listing row #$idx has invalid mint_mode: $mode");
        }
    }
});

t('POST /api/buy-order.php with empty body returns 400', function () use ($base): void {
    $resp = httpReq('POST', "$base/api/buy-order.php", []);
    if (($resp['status'] ?? 0) !== 400) {
        throw new RuntimeException('expected 400 for empty payload');
    }
});

t('POST /api/buy-order.php with malformed tx_hash returns 400', function () use ($base, $sampleToken): void {
    $resp = httpReq('POST', "$base/api/buy-order.php", [
        'token_id' => $sampleToken,
        'buyer_addr' => 'addr1qexamplebuyeraddressxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx',
        'tx_hash' => 'abc123',
        'amount_lovelace' => 1000000,
    ]);
    if (($resp['status'] ?? 0) !== 400) {
        throw new RuntimeException('expected 400 for malformed tx_hash');
    }
});

t('POST /api/buy-order.php for unknown token returns 404', function () use ($base): void {
    $resp = httpReq('POST', "$base/api/buy-order.php", [
        'token_id' => 'qd-silver-9999999',
        'buyer_addr' => 'addr1qexamplebuyeraddressxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx',
        'tx_hash' => bin2hex(random_bytes(32)),
        'amount_lovelace' => 1000000,
    ]);
    if (($resp['status'] ?? 0) !== 404) {
        throw new RuntimeException('expected 404 for unknown token');
    }
});

if (is_resource($proc)) {
    proc_terminate($proc);
    fclose($pipes[0]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($proc);
}

echo "\nResults: $pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
