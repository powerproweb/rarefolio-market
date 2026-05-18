<?php
/**
 * HTTP-triggered Founder reconciliation runner.
 *
 * Purpose:
 *   Run the Founder state reconciliation without SSH shell access.
 *
 * Auth:
 *   Uses DEPLOY_WEBHOOK_SECRET from .env and requires X-Deploy-Secret header.
 *
 * Method:
 *   POST only.
 */
declare(strict_types=1);
header('Content-Type: application/json');

if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'ok' => false,
        'error' => 'method_not_allowed',
    ]);
    exit;
}

$envFile = __DIR__ . '/../.env';
$secret = '';
if (is_file($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        if (str_starts_with(trim($line), 'DEPLOY_WEBHOOK_SECRET=')) {
            $secret = trim(substr($line, strlen('DEPLOY_WEBHOOK_SECRET=')));
            break;
        }
    }
}

$provided = $_SERVER['HTTP_X_DEPLOY_SECRET'] ?? '';
if ($secret === '' || !hash_equals($secret, $provided)) {
    http_response_code(401);
    echo json_encode([
        'ok' => false,
        'error' => 'unauthorized',
    ]);
    exit;
}

require_once __DIR__ . '/../src/Config.php';
require_once __DIR__ . '/../src/Db.php';
require_once __DIR__ . '/../src/Ops/FoundersReconcile.php';

use RareFolio\Config;
use RareFolio\Db;
use RareFolio\Ops\FoundersReconcile;

try {
    Config::load(__DIR__ . '/../.env');
    $pdo = Db::pdo();
    $result = FoundersReconcile::run($pdo);
    http_response_code(($result['ok'] ?? false) ? 200 : 500);
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => $e->getMessage(),
    ]);
}
