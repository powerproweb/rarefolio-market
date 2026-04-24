<?php
/**
 * HTTP-triggered migration runner.
 *
 * Called by GitHub Actions deploy workflow after file sync, since BlueHost
 * shared hosting disables SSH shell access (can't run `php db/migrate.php`
 * directly via SSH).
 *
 * Protected by the same DEPLOY_WEBHOOK_SECRET used in the workflow.
 * Set this secret in:
 *   - marketplace .env  → DEPLOY_WEBHOOK_SECRET=<64-char hex>
 *   - GitHub repo secrets → DEPLOY_WEBHOOK_SECRET=<same value>
 *
 * Generate a secret: php scripts/gen-webhook-secret.php
 */
declare(strict_types=1);

// ---- Auth: constant-time compare against DEPLOY_WEBHOOK_SECRET ----
$envFile = __DIR__ . '/../.env';
$secret  = '';
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
    echo json_encode(['ok' => false, 'error' => 'unauthorized']);
    exit;
}

// ---- Run migrations ----
$migrationsDir = __DIR__ . '/../db/migrate.php';
if (!is_file($migrationsDir)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'migrate.php not found']);
    exit;
}

ob_start();
$exitCode = 0;
try {
    // Capture stdout/stderr from the migration runner
    include $migrationsDir;
} catch (Throwable $e) {
    $exitCode = 1;
    echo 'EXCEPTION: ' . $e->getMessage() . "\n";
}
$output = ob_get_clean();

http_response_code($exitCode === 0 ? 200 : 500);
header('Content-Type: application/json');
echo json_encode([
    'ok'     => $exitCode === 0,
    'output' => $output,
]);
