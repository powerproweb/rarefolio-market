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
header('Content-Type: application/json');

const RF_HTTP_MIGRATION_MODES = ['plan', 'dry-run', 'apply'];

/**
 * @return array<string,mixed>
 */
function parseJsonBody(): array
{
    $raw = file_get_contents('php://input');
    if (!is_string($raw) || trim($raw) === '') {
        return [];
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function parseDryRunDbValue(mixed $value): ?string
{
    if ($value === null) {
        return null;
    }
    if (!is_string($value)) {
        throw new InvalidArgumentException('dry_run_db must be a string');
    }

    $name = trim($value);
    if ($name === '') {
        return null;
    }
    if (!preg_match('/^[A-Za-z0-9_]+$/', $name)) {
        throw new InvalidArgumentException('dry_run_db must contain only letters, numbers, and underscore');
    }
    if (strlen($name) > 64) {
        throw new InvalidArgumentException('dry_run_db must be at most 64 characters');
    }
    return $name;
}

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

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method_not_allowed']);
    exit;
}

$jsonBody = parseJsonBody();
$mode = $_POST['mode'] ?? $jsonBody['mode'] ?? $_GET['mode'] ?? 'apply';
if (!is_string($mode) || !in_array($mode, RF_HTTP_MIGRATION_MODES, true)) {
    http_response_code(400);
    echo json_encode([
        'ok' => false,
        'error' => 'invalid_mode',
        'allowed_modes' => RF_HTTP_MIGRATION_MODES,
    ]);
    exit;
}

try {
    $dryRunDb = parseDryRunDbValue($_POST['dry_run_db'] ?? $jsonBody['dry_run_db'] ?? $_GET['dry_run_db'] ?? null);
} catch (InvalidArgumentException $e) {
    http_response_code(400);
    echo json_encode([
        'ok' => false,
        'error' => 'invalid_dry_run_db',
        'detail' => $e->getMessage(),
    ]);
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
$startedAt = microtime(true);
try {
    putenv('RF_MIGRATION_MODE=' . $mode);
    $GLOBALS['RF_MIGRATION_MODE'] = $mode;
    if ($dryRunDb !== null) {
        putenv('RF_MIGRATION_DRY_RUN_DB=' . $dryRunDb);
        $GLOBALS['RF_MIGRATION_DRY_RUN_DB'] = $dryRunDb;
    } else {
        putenv('RF_MIGRATION_DRY_RUN_DB');
        unset($GLOBALS['RF_MIGRATION_DRY_RUN_DB']);
    }
    // Capture stdout/stderr from the migration runner
    include $migrationsDir;
} catch (Throwable $e) {
    $exitCode = 1;
    echo 'EXCEPTION: ' . $e->getMessage() . "\n";
}
$output = ob_get_clean();
$elapsedSeconds = microtime(true) - $startedAt;

http_response_code($exitCode === 0 ? 200 : 500);
echo json_encode([
    'ok'     => $exitCode === 0,
    'mode'   => $mode,
    'dry_run_db' => $dryRunDb,
    'elapsed_seconds' => round($elapsedSeconds, 3),
    'output' => $output,
]);
