<?php
/**
 * Sidecar proxy for admin JavaScript.
 *
 * Admin pages need to call the sidecar (localhost:4000) from the browser,
 * but that would require CORS on the sidecar. Instead, browser JS calls
 * this PHP file, which forwards the request server-side.
 *
 * Only GET requests are proxied. Only admin-authenticated users can call this.
 * Only paths starting with known safe prefixes are forwarded.
 *
 * Usage:
 *   fetch('/admin/sidecar-proxy.php?path=/mint/policy-id%3Fenv_key=FOUNDERS')
 */
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

use RareFolio\Config;
use RareFolio\Sidecar\Client as SidecarClient;

$allowedPrefixes = ['/mint/', '/sweep/', '/health'];

$path = (string) ($_GET['path'] ?? '');
if ($path === '') {
    http_response_code(400);
    echo json_encode(['error' => 'missing path param']);
    exit;
}

$allowed = false;
foreach ($allowedPrefixes as $prefix) {
    if (str_starts_with($path, $prefix)) { $allowed = true; break; }
}
if (!$allowed) {
    http_response_code(403);
    echo json_encode(['error' => 'path not allowed']);
    exit;
}

$baseUrl = rtrim((string) Config::get('SIDECAR_BASE_URL', 'http://localhost:4000'), '/');
$url     = $baseUrl . $path;

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 15,
    CURLOPT_HTTPHEADER     => ['Accept: application/json'],
]);
$resp = curl_exec($ch);
$code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err  = curl_error($ch);
curl_close($ch);

if ($resp === false) {
    http_response_code(502);
    echo json_encode(['error' => "Sidecar curl error: $err"]);
    exit;
}

http_response_code($code);
header('Content-Type: application/json');
echo $resp;
