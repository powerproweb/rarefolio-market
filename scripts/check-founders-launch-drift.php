<?php
declare(strict_types=1);

/**
 * check-founders-launch-drift.php
 *
 * Pre-announcement guard for Founders Block 88 token state.
 *
 * Verifies each Founders token is in the launch baseline:
 * - status.primary_sale = minted
 * - status.listing = listed_fixed
 * - status.custody = platform
 * - bar_serial matches expected value
 *
 * Listings scan checks visibility by paging /api/v1/listings?format=fixed
 * and confirming each Founders token appears in active fixed listings.
 *
 * Usage:
 *   php scripts/check-founders-launch-drift.php
 *   php scripts/check-founders-launch-drift.php --base=https://market.rarefolio.io
 *   php scripts/check-founders-launch-drift.php --insecure
 *   php scripts/check-founders-launch-drift.php --json
 *   php scripts/check-founders-launch-drift.php --max-pages=30
 *   php scripts/check-founders-launch-drift.php --expected-bar-serial=E101837
 *   php scripts/check-founders-launch-drift.php --skip-listings-scan
 *
 * Exit codes:
 *   0 = guard passed
 *   1 = drift detected
 *   2 = usage / transport / response-shape error
 */

const FOUNDERS_TOKENS = [
    'qd-silver-0000705',
    'qd-silver-0000706',
    'qd-silver-0000707',
    'qd-silver-0000708',
    'qd-silver-0000709',
    'qd-silver-0000710',
    'qd-silver-0000711',
    'qd-silver-0000712',
];

const EXPECTED_PRIMARY = 'minted';
const EXPECTED_LISTING = 'listed_fixed';
const EXPECTED_CUSTODY = 'platform';
const EXPECTED_BAR_SERIAL = 'E101837';

$base = 'https://market.rarefolio.io';
$insecureTls = false;
$jsonOut = false;
$maxPages = 20;
$skipListingsScan = false;
$expectedBarSerial = EXPECTED_BAR_SERIAL;

foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--base=(.+)$/', $arg, $m) === 1) {
        $base = trim((string) $m[1]);
        continue;
    }
    if ($arg === '--insecure') {
        $insecureTls = true;
        continue;
    }
    if ($arg === '--json') {
        $jsonOut = true;
        continue;
    }
    if (preg_match('/^--max-pages=(\d+)$/', $arg, $m) === 1) {
        $maxPages = max(1, min(200, (int) $m[1]));
        continue;
    }
    if (preg_match('/^--expected-bar-serial=(.+)$/', $arg, $m) === 1) {
        $candidate = strtoupper(trim((string) $m[1]));
        if ($candidate === '' || preg_match('/^[A-Z][0-9]{5,12}$/', $candidate) !== 1) {
            fwrite(STDERR, "Invalid --expected-bar-serial value: {$candidate}\n");
            exit(2);
        }
        $expectedBarSerial = $candidate;
        continue;
    }
    if ($arg === '--skip-listings-scan') {
        $skipListingsScan = true;
        continue;
    }
    if ($arg === '--with-listings-scan') {
        $skipListingsScan = false;
        continue;
    }
    if ($arg === '--help' || $arg === '-h') {
        printUsage();
        exit(0);
    }
    fwrite(STDERR, "Unknown argument: {$arg}\n");
    printUsage();
    exit(2);
}

$base = rtrim($base, '/');
if ($base === '' || !preg_match('#^https?://#i', $base)) {
    fwrite(STDERR, "Invalid --base URL: {$base}\n");
    exit(2);
}

$tokenChecks = [];
$drifts = [];
$errors = [];

foreach (FOUNDERS_TOKENS as $tokenId) {
    $tokenUrl = $base . '/api/v1/tokens/' . rawurlencode($tokenId) . '?guard_ts=' . rawurlencode((string) microtime(true));
    $resp = httpGetJson($tokenUrl, $insecureTls);
    if ($resp['error'] !== null) {
        $errors[] = "token {$tokenId}: " . $resp['error'];
        continue;
    }
    if ($resp['status'] !== 200) {
        $errors[] = "token {$tokenId}: unexpected HTTP " . $resp['status'];
        continue;
    }

    $payload = $resp['body'];
    if (!is_array($payload) || !isset($payload['data']) || !is_array($payload['data'])) {
        $errors[] = "token {$tokenId}: unexpected response shape";
        continue;
    }

    $data = $payload['data'];
    $status = isset($data['status']) && is_array($data['status']) ? $data['status'] : [];
    $actualPrimary = normalizeLowerString($status['primary_sale'] ?? null);
    $actualListing = normalizeLowerString($status['listing'] ?? null);
    $actualCustody = normalizeLowerString($status['custody'] ?? null);
    $actualBarSerial = strtoupper(trim((string) ($data['bar_serial'] ?? '')));
    $mintTxHash = trim((string) (($data['chain']['mint_tx_hash'] ?? '') ?: ''));

    $tokenChecks[$tokenId] = [
        'primary_sale' => $actualPrimary,
        'listing' => $actualListing,
        'custody' => $actualCustody,
        'bar_serial' => $actualBarSerial,
        'mint_tx_hash_present' => ($mintTxHash !== ''),
    ];

    if ($actualPrimary !== EXPECTED_PRIMARY) {
        $drifts[] = "{$tokenId}: primary_sale expected '" . EXPECTED_PRIMARY . "', got '" . ($actualPrimary !== '' ? $actualPrimary : 'null') . "'";
    }
    if ($actualListing !== EXPECTED_LISTING) {
        $drifts[] = "{$tokenId}: listing expected '" . EXPECTED_LISTING . "', got '" . ($actualListing !== '' ? $actualListing : 'null') . "'";
    }
    if ($actualCustody !== EXPECTED_CUSTODY) {
        $drifts[] = "{$tokenId}: custody expected '" . EXPECTED_CUSTODY . "', got '" . ($actualCustody !== '' ? $actualCustody : 'null') . "'";
    }
    if ($actualBarSerial === '') {
        $drifts[] = "{$tokenId}: bar_serial is empty";
    } elseif ($expectedBarSerial !== '' && $actualBarSerial !== $expectedBarSerial) {
        $drifts[] = "{$tokenId}: bar_serial expected '" . $expectedBarSerial . "', got '" . $actualBarSerial . "'";
    }
    if ($mintTxHash === '') {
        $drifts[] = "{$tokenId}: chain.mint_tx_hash is empty";
    }
}

$listingScan = [
    'performed' => !$skipListingsScan,
    'pages_scanned' => 0,
    'found_tokens' => [],
    'missing_tokens' => [],
];

if (!$skipListingsScan) {
    $found = [];
    $limit = 100;
    $offset = 0;
    $scanErrored = false;

    for ($page = 1; $page <= $maxPages; $page++) {
        $listUrl = $base . '/api/v1/listings?format=fixed&limit=' . $limit . '&offset=' . $offset . '&guard_ts=' . rawurlencode((string) microtime(true));
        $resp = httpGetJson($listUrl, $insecureTls);
        $listingScan['pages_scanned'] = $page;

        if ($resp['error'] !== null) {
            $errors[] = 'listings scan: ' . $resp['error'];
            $scanErrored = true;
            break;
        }
        if ($resp['status'] !== 200) {
            $errors[] = 'listings scan: unexpected HTTP ' . $resp['status'];
            $scanErrored = true;
            break;
        }
        $payload = $resp['body'];
        if (!is_array($payload) || !isset($payload['data']) || !is_array($payload['data'])) {
            $errors[] = 'listings scan: unexpected response shape';
            $scanErrored = true;
            break;
        }

        $rows = $payload['data']['listings'] ?? null;
        if (!is_array($rows)) {
            $errors[] = 'listings scan: missing data.listings array';
            $scanErrored = true;
            break;
        }

        if (count($rows) === 0) {
            break;
        }

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $cnftId = trim((string) ($row['cnft_id'] ?? ''));
            if ($cnftId !== '' && in_array($cnftId, FOUNDERS_TOKENS, true)) {
                $found[$cnftId] = true;
            }
        }

        if (count($found) === count(FOUNDERS_TOKENS)) {
            break;
        }
        $offset += $limit;
    }

    $foundTokens = array_keys($found);
    sort($foundTokens, SORT_STRING);
    $listingScan['found_tokens'] = $foundTokens;
    $missing = $scanErrored
        ? []
        : array_values(array_filter(FOUNDERS_TOKENS, static function (string $tokenId) use ($found): bool {
            return !isset($found[$tokenId]);
        }));
    $listingScan['missing_tokens'] = $missing;

    foreach ($missing as $tokenId) {
        $drifts[] = "{$tokenId}: not found in active fixed listings scan";
    }
}

$result = [
    'ok' => count($drifts) === 0 && count($errors) === 0,
    'base' => $base,
    'expected' => [
        'primary_sale' => EXPECTED_PRIMARY,
        'listing' => EXPECTED_LISTING,
        'custody' => EXPECTED_CUSTODY,
        'bar_serial' => $expectedBarSerial,
    ],
    'tokens' => $tokenChecks,
    'listings_scan' => $listingScan,
    'drifts' => $drifts,
    'errors' => $errors,
    'checked_at_utc' => gmdate('c'),
];

if ($jsonOut) {
    echo json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
} else {
    if ($result['ok']) {
        echo "PASS Founders launch drift guard\n";
        echo "Base: {$base}\n";
        echo "Checked tokens: " . count(FOUNDERS_TOKENS) . "\n";
        if (!$skipListingsScan) {
            echo "Listings scan pages: " . $listingScan['pages_scanned'] . "\n";
        }
    } else {
        echo "FAIL Founders launch drift guard\n";
        echo "Base: {$base}\n";
        if (count($drifts) > 0) {
            echo "Drifts:\n";
            foreach ($drifts as $line) {
                echo " - {$line}\n";
            }
        }
        if (count($errors) > 0) {
            echo "Errors:\n";
            foreach ($errors as $line) {
                echo " - {$line}\n";
            }
        }
    }
}

if (count($errors) > 0) {
    exit(2);
}
exit(count($drifts) > 0 ? 1 : 0);

/**
 * @return array{status:int,body:mixed,error:?string}
 */
function httpGetJson(string $url, bool $insecureTls): array
{
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        if (!is_resource($ch) && !($ch instanceof CurlHandle)) {
            return httpGetJsonViaCliCurl($url, $insecureTls);
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_HTTPGET => true,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
        ]);
        if ($insecureTls) {
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        }
        $raw = curl_exec($ch);
        if ($raw === false) {
            $err = curl_error($ch);
            curl_close($ch);
            $fallback = httpGetJsonViaCliCurl($url, $insecureTls);
            if ($fallback['error'] === null || $fallback['status'] > 0) {
                return $fallback;
            }
            return ['status' => 0, 'body' => null, 'error' => 'curl error: ' . $err];
        }
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $body = json_decode((string) $raw, true);
        return ['status' => $status, 'body' => $body, 'error' => null];
    }

    $ctx = stream_context_create([
        'http' => [
            'method' => 'GET',
            'ignore_errors' => true,
            'timeout' => 15,
            'header' => "Accept: application/json\r\n",
        ],
        'ssl' => [
            'verify_peer' => !$insecureTls,
            'verify_peer_name' => !$insecureTls,
            'allow_self_signed' => $insecureTls,
        ],
    ]);
    $raw = @file_get_contents($url, false, $ctx);
    if ($raw === false) {
        return httpGetJsonViaCliCurl($url, $insecureTls);
    }
    $status = 0;
    if (isset($http_response_header) && is_array($http_response_header)) {
        foreach ($http_response_header as $headerLine) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $headerLine, $m) === 1) {
                $status = (int) $m[1];
            }
        }
    }
    $body = json_decode((string) $raw, true);
    if ($status === 0) {
        $fallback = httpGetJsonViaCliCurl($url, $insecureTls);
        if ($fallback['error'] === null || $fallback['status'] > 0) {
            return $fallback;
        }
    }
    return ['status' => $status, 'body' => $body, 'error' => null];
}

/**
 * @return array{status:int,body:mixed,error:?string}
 */
function httpGetJsonViaCliCurl(string $url, bool $insecureTls): array
{
    $curlBin = PHP_OS_FAMILY === 'Windows' ? 'curl.exe' : 'curl';
    $writeOut = "\n__CURL_STATUS__:%{http_code}";
    $args = [
        $curlBin,
        '--silent',
        '--show-error',
        '--request',
        'GET',
        '--url',
        $url,
        '--connect-timeout',
        '8',
        '--max-time',
        '15',
        '--header',
        'Accept: application/json',
        '--write-out',
        $writeOut,
    ];
    if ($insecureTls) {
        $args[] = '--insecure';
    }

    $desc = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $options = PHP_OS_FAMILY === 'Windows' ? ['bypass_shell' => true] : [];
    $proc = @proc_open($args, $desc, $pipes, null, null, $options);
    if (!is_resource($proc)) {
        return [
            'status' => 0,
            'body' => null,
            'error' => 'curl CLI fallback failed to start process',
        ];
    }

    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($proc);

    $out = is_string($stdout) ? $stdout : '';
    $err = trim((string) $stderr);
    $markerPos = strrpos($out, '__CURL_STATUS__:');
    if ($markerPos === false) {
        return [
            'status' => 0,
            'body' => null,
            'error' => $err !== '' ? $err : 'curl CLI output missing status marker',
        ];
    }

    $statusRaw = trim(substr($out, $markerPos + strlen('__CURL_STATUS__:')));
    $status = (int) $statusRaw;
    $bodyRaw = substr($out, 0, $markerPos);
    $body = json_decode((string) $bodyRaw, true);

    if ($exit !== 0 && $status === 0) {
        return [
            'status' => 0,
            'body' => null,
            'error' => $err !== '' ? $err : 'curl CLI request failed',
        ];
    }

    return [
        'status' => $status,
        'body' => $body,
        'error' => null,
    ];
}

function normalizeLowerString(mixed $value): string
{
    if (!is_string($value)) {
        return '';
    }
    return strtolower(trim($value));
}

function printUsage(): void
{
    echo "Usage: php scripts/check-founders-launch-drift.php [--base=<url>] [--insecure] [--json] [--max-pages=<n>] [--expected-bar-serial=<serial>] [--skip-listings-scan]\n";
}
