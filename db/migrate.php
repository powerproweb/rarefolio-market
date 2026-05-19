<?php
/**
 * Minimal schema migration runner.
 *
 * Reads every .sql file in db/migrations/ in lexical order and supports:
 *  - apply: apply pending migrations to configured DB (default)
 *  - plan: list pending migrations with guard checks only
 *  - dry-run: apply pending migrations to a disposable shadow schema
 *
 * Usage:
 *   php db/migrate.php
 *   php db/migrate.php --mode=plan
 *   php db/migrate.php --mode=dry-run
 *   php db/migrate.php --mode=dry-run --dry-run-db=rarefolio_market_dryrun
 */
declare(strict_types=1);

require_once __DIR__ . '/../src/Config.php';
require_once __DIR__ . '/../src/Db.php';

use RareFolio\Config;
use RareFolio\Db;

const RF_MIGRATION_MODES = ['apply', 'plan', 'dry-run'];

function migrationLog(string $message, bool $error = false): void
{
    $line = rtrim($message, "\r\n") . "\n";
    if ($error && defined('STDERR')) {
        fwrite(STDERR, $line);
        return;
    }
    if (!$error && defined('STDOUT')) {
        fwrite(STDOUT, $line);
        return;
    }
    echo $line;
}

function hasUnresolvedPlaceholderGuard(string $sql): bool
{
    return preg_match("/^\s*SET\s+@[A-Za-z0-9_]+\s*:=\s*'REPLACE_[A-Z0-9_]+'\s*;/im", $sql) === 1;
}

function isOpsOnlyMigration(string $sql): bool
{
    return preg_match('/^\s*--\s*@ops_only\b/im', $sql) === 1;
}

function hasDynamicSqlControlStatements(string $sql): bool
{
    return preg_match('/^\s*(PREPARE|DEALLOCATE\s+PREPARE|EXECUTE|SIGNAL\s+SQLSTATE)\b/im', $sql) === 1;
}

function quoteIdentifier(string $name): string
{
    if (!preg_match('/^[A-Za-z0-9_]+$/', $name)) {
        throw new RuntimeException("Unsafe SQL identifier: $name");
    }
    return "`$name`";
}

function readMigrationSql(string $file): string
{
    $sql = file_get_contents($file);
    if ($sql === false || trim($sql) === '') {
        throw new RuntimeException(basename($file) . ': empty or unreadable migration file');
    }
    return $sql;
}

function assertMigrationGuards(string $name, string $sql): void
{
    if (hasDynamicSqlControlStatements($sql)) {
        throw new RuntimeException($name . ": contains dynamic SQL control statements. Mark this migration with '-- @ops_only' and execute manually.");
    }
    if (hasUnresolvedPlaceholderGuard($sql)) {
        throw new RuntimeException($name . ': contains unresolved REPLACE_* placeholders in an auto-run migration.');
    }
}

function cliValueFromArgv(array $argv, string $flag): ?string
{
    $prefix = $flag . '=';
    for ($i = 1, $count = count($argv); $i < $count; $i++) {
        $arg = (string) $argv[$i];
        if (str_starts_with($arg, $prefix)) {
            return (string) substr($arg, strlen($prefix));
        }
        if ($arg === $flag && isset($argv[$i + 1])) {
            return (string) $argv[$i + 1];
        }
    }
    return null;
}

function cliModeFromArgv(array $argv): ?string
{
    return cliValueFromArgv($argv, '--mode');
}

function cliDryRunDbFromArgv(array $argv): ?string
{
    return cliValueFromArgv($argv, '--dry-run-db');
}

function resolveMigrationMode(): string
{
    $mode = null;

    $globalMode = $GLOBALS['RF_MIGRATION_MODE'] ?? null;
    if (is_string($globalMode) && $globalMode !== '') {
        $mode = $globalMode;
    }

    if ($mode === null) {
        $envMode = getenv('RF_MIGRATION_MODE');
        if (is_string($envMode) && $envMode !== '') {
            $mode = $envMode;
        }
    }

    if ($mode === null && PHP_SAPI === 'cli') {
        global $argv;
        if (is_array($argv)) {
            $mode = cliModeFromArgv($argv);
        }
    }

    if ($mode === null || $mode === '') {
        $mode = 'apply';
    }

    if (!in_array($mode, RF_MIGRATION_MODES, true)) {
        throw new RuntimeException("Invalid migration mode '$mode'. Allowed: apply, plan, dry-run.");
    }

    return $mode;
}

function validateDryRunDbName(string $candidate, string $sourceDb): string
{
    $name = trim($candidate);
    if ($name === '') {
        throw new RuntimeException('Dry-run DB name is empty.');
    }
    if (!preg_match('/^[A-Za-z0-9_]+$/', $name)) {
        throw new RuntimeException("Invalid dry-run DB name '$name'. Use only letters, numbers, and underscore.");
    }
    if (strlen($name) > 64) {
        throw new RuntimeException("Dry-run DB name '$name' is too long (max 64 characters).");
    }
    if (strcasecmp($name, $sourceDb) === 0) {
        throw new RuntimeException('Dry-run DB must be different from DB_NAME.');
    }
    return $name;
}

function resolveDryRunDbName(string $sourceDb): ?string
{
    $candidate = null;

    $globalDb = $GLOBALS['RF_MIGRATION_DRY_RUN_DB'] ?? null;
    if (is_string($globalDb) && trim($globalDb) !== '') {
        $candidate = $globalDb;
    }

    if ($candidate === null) {
        $envDb = getenv('RF_MIGRATION_DRY_RUN_DB');
        if (is_string($envDb) && trim($envDb) !== '') {
            $candidate = $envDb;
        }
    }

    if ($candidate === null && PHP_SAPI === 'cli') {
        global $argv;
        if (is_array($argv)) {
            $argDb = cliDryRunDbFromArgv($argv);
            if (is_string($argDb) && trim($argDb) !== '') {
                $candidate = $argDb;
            }
        }
    }

    if ($candidate === null) {
        $configDb = Config::get('MIGRATION_DRY_RUN_DB');
        if (is_string($configDb) && trim($configDb) !== '') {
            $candidate = $configDb;
        }
    }

    if ($candidate === null) {
        return null;
    }

    return validateDryRunDbName($candidate, $sourceDb);
}

function ensureSchemaMigrationsTable(PDO $pdo): void
{
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS schema_migrations (
            filename  VARCHAR(191) NOT NULL PRIMARY KEY,
            applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
}

/**
 * @return array<string,bool>
 */
function loadAppliedMigrations(PDO $pdo): array
{
    ensureSchemaMigrationsTable($pdo);

    $stmt = $pdo->query('SELECT filename FROM schema_migrations');
    if ($stmt === false) {
        return [];
    }

    $rows = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
    $map = [];
    foreach ($rows as $row) {
        $map[(string) $row] = true;
    }
    return $map;
}

/**
 * @return list<string>
 */
function listMigrationFiles(): array
{
    $files = glob(__DIR__ . '/migrations/*.sql') ?: [];
    sort($files, SORT_STRING);
    return $files;
}

/**
 * @param list<string> $files
 * @param array<string,bool> $applied
 */
function runPlan(array $files, array $applied): int
{
    $pending = 0;
    foreach ($files as $file) {
        $name = basename($file);
        if (isset($applied[$name])) {
            migrationLog("skip  $name (already applied)");
            continue;
        }

        $sql = readMigrationSql($file);
        if (isOpsOnlyMigration($sql)) {
            migrationLog("skip  $name (ops-only migration)");
            continue;
        }

        assertMigrationGuards($name, $sql);
        migrationLog("plan  $name (pending)");
        $pending++;
    }

    migrationLog('');
    migrationLog("Done. Pending $pending migration(s).");
    return $pending;
}

/**
 * @param list<string> $files
 * @param array<string,bool> $applied
 */
function runApply(PDO $pdo, array $files, array $applied): int
{
    $ran = 0;
    foreach ($files as $file) {
        $name = basename($file);
        if (isset($applied[$name])) {
            migrationLog("skip  $name (already applied)");
            continue;
        }

        $sql = readMigrationSql($file);
        if (isOpsOnlyMigration($sql)) {
            migrationLog("skip  $name (ops-only migration)");
            continue;
        }

        assertMigrationGuards($name, $sql);

        try {
            $pdo->beginTransaction();
            $pdo->exec($sql);
            $stmt = $pdo->prepare('INSERT INTO schema_migrations (filename) VALUES (?)');
            $stmt->execute([$name]);
            if ($pdo->inTransaction()) {
                $pdo->commit();
            }
            migrationLog("ok    $name");
            $ran++;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw new RuntimeException($name . ': ' . $e->getMessage(), 0, $e);
        }
    }

    migrationLog('');
    migrationLog("Done. Applied $ran migration(s).");
    return $ran;
}

/**
 * @return array{host:string,port:int,name:string,user:string,pass:string}
 */
function migrationDbConfig(): array
{
    return [
        'host' => (string) Config::get('DB_HOST', '127.0.0.1'),
        'port' => Config::int('DB_PORT', 3306),
        'name' => Config::required('DB_NAME'),
        'user' => Config::required('DB_USER'),
        'pass' => (string) Config::get('DB_PASS', ''),
    ];
}

/**
 * @param array{host:string,port:int,name:string,user:string,pass:string} $config
 */
function pdoFromConfig(array $config, ?string $dbName = null): PDO
{
    $dsn = 'mysql:host=' . $config['host'] . ';port=' . $config['port'] . ';charset=utf8mb4';
    if ($dbName !== null && $dbName !== '') {
        $dsn = 'mysql:host=' . $config['host'] . ';port=' . $config['port'] . ';dbname=' . $dbName . ';charset=utf8mb4';
    }

    return new PDO($dsn, $config['user'], $config['pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
}

function randomSuffix(): string
{
    try {
        return substr(bin2hex(random_bytes(4)), 0, 8);
    } catch (Throwable) {
        return substr((string) mt_rand(10000000, 99999999), 0, 8);
    }
}

function makeShadowDbName(string $sourceDb): string
{
    $sanitized = preg_replace('/[^A-Za-z0-9_]/', '_', $sourceDb);
    if (!is_string($sanitized) || $sanitized === '') {
        $sanitized = 'db';
    }

    $name = 'rfmig_' . $sanitized . '_' . gmdate('YmdHis') . '_' . randomSuffix();
    if (strlen($name) > 64) {
        $name = substr($name, 0, 64);
    }
    return rtrim($name, '_');
}

function createShadowDatabase(PDO $adminPdo, string $sourceDb, string $shadowDb): void
{
    $stmt = $adminPdo->prepare(
        'SELECT DEFAULT_CHARACTER_SET_NAME, DEFAULT_COLLATION_NAME
         FROM information_schema.SCHEMATA
         WHERE SCHEMA_NAME = ?'
    );
    $stmt->execute([$sourceDb]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) {
        throw new RuntimeException("Source schema not found: $sourceDb");
    }

    $charset = (string) ($row['DEFAULT_CHARACTER_SET_NAME'] ?? '');
    $collation = (string) ($row['DEFAULT_COLLATION_NAME'] ?? '');
    if (!preg_match('/^[A-Za-z0-9_]+$/', $charset) || !preg_match('/^[A-Za-z0-9_]+$/', $collation)) {
        throw new RuntimeException("Unsafe schema defaults for source DB: $sourceDb");
    }

    $qShadow = quoteIdentifier($shadowDb);
    $adminPdo->exec("CREATE DATABASE $qShadow CHARACTER SET $charset COLLATE $collation");
}

function shadowDatabaseExists(PDO $adminPdo, string $shadowDb): bool
{
    $stmt = $adminPdo->prepare(
        'SELECT COUNT(*)
         FROM information_schema.SCHEMATA
         WHERE SCHEMA_NAME = ?'
    );
    $stmt->execute([$shadowDb]);
    return (int) $stmt->fetchColumn() > 0;
}

function resetShadowSchema(PDO $adminPdo, string $shadowDb): void
{
    $stmt = $adminPdo->prepare(
        'SELECT TABLE_NAME, TABLE_TYPE
         FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = ?
         ORDER BY TABLE_NAME'
    );
    $stmt->execute([$shadowDb]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    if (count($rows) === 0) {
        return;
    }

    $qShadow = quoteIdentifier($shadowDb);
    $adminPdo->exec('SET FOREIGN_KEY_CHECKS=0');
    try {
        foreach ($rows as $row) {
            $table = (string) ($row['TABLE_NAME'] ?? '');
            $type = strtoupper((string) ($row['TABLE_TYPE'] ?? 'BASE TABLE'));
            if ($table === '') {
                continue;
            }
            $qTable = quoteIdentifier($table);
            if ($type === 'VIEW') {
                $adminPdo->exec("DROP VIEW IF EXISTS $qShadow.$qTable");
                continue;
            }
            $adminPdo->exec("DROP TABLE IF EXISTS $qShadow.$qTable");
        }
    } finally {
        $adminPdo->exec('SET FOREIGN_KEY_CHECKS=1');
    }
}

function cloneBaseTables(PDO $adminPdo, string $sourceDb, string $shadowDb): void
{
    $stmt = $adminPdo->prepare(
        'SELECT TABLE_NAME, TABLE_TYPE
         FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = ?
         ORDER BY TABLE_NAME'
    );
    $stmt->execute([$sourceDb]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $qSource = quoteIdentifier($sourceDb);
    $qShadow = quoteIdentifier($shadowDb);

    foreach ($rows as $row) {
        $table = (string) ($row['TABLE_NAME'] ?? '');
        $type = strtoupper((string) ($row['TABLE_TYPE'] ?? ''));
        if ($table === '') {
            continue;
        }
        if ($type !== 'BASE TABLE') {
            migrationLog("skip  $table (non-table object not cloned in dry-run)");
            continue;
        }

        $qTable = quoteIdentifier($table);
        $adminPdo->exec("CREATE TABLE $qShadow.$qTable LIKE $qSource.$qTable");
    }
}

function copySchemaMigrationsTable(PDO $adminPdo, string $sourceDb, string $shadowDb): void
{
    $existsStmt = $adminPdo->prepare(
        'SELECT COUNT(*)
         FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?'
    );
    $existsStmt->execute([$sourceDb, 'schema_migrations']);
    $exists = (int) $existsStmt->fetchColumn() > 0;
    if (!$exists) {
        return;
    }

    $qSource = quoteIdentifier($sourceDb);
    $qShadow = quoteIdentifier($shadowDb);
    $qTable = quoteIdentifier('schema_migrations');
    $adminPdo->exec("INSERT INTO $qShadow.$qTable (filename, applied_at) SELECT filename, applied_at FROM $qSource.$qTable");
}

/**
 * @param list<string> $files
 */
function runDryRun(array $files): int
{
    $config = migrationDbConfig();
    $sourceDb = $config['name'];
    $configuredShadowDb = resolveDryRunDbName($sourceDb);
    $shadowDb = $configuredShadowDb ?? makeShadowDbName($sourceDb);
    $usesConfiguredShadowDb = $configuredShadowDb !== null;

    $adminPdo = pdoFromConfig($config, null);

    migrationLog("dry-run target source DB: $sourceDb");
    if ($usesConfiguredShadowDb) {
        migrationLog("dry-run shadow DB (configured): $shadowDb");
    } else {
        migrationLog("dry-run shadow DB (ephemeral): $shadowDb");
    }

    try {
        if ($usesConfiguredShadowDb) {
            if (!shadowDatabaseExists($adminPdo, $shadowDb)) {
                throw new RuntimeException("Configured dry-run DB '$shadowDb' does not exist.");
            }
            resetShadowSchema($adminPdo, $shadowDb);
        } else {
            createShadowDatabase($adminPdo, $sourceDb, $shadowDb);
        }
        cloneBaseTables($adminPdo, $sourceDb, $shadowDb);
        copySchemaMigrationsTable($adminPdo, $sourceDb, $shadowDb);

        $shadowPdo = pdoFromConfig($config, $shadowDb);
        $applied = loadAppliedMigrations($shadowPdo);
        $ran = runApply($shadowPdo, $files, $applied);
        if ($usesConfiguredShadowDb) {
            migrationLog("dry-run complete for configured shadow DB: $shadowDb");
        } else {
            migrationLog("dry-run complete for ephemeral shadow DB: $shadowDb");
        }
        return $ran;
    } finally {
        if (!$usesConfiguredShadowDb) {
            $qShadow = quoteIdentifier($shadowDb);
            try {
                $adminPdo->exec("DROP DATABASE IF EXISTS $qShadow");
                migrationLog("dry-run cleanup: dropped shadow DB $shadowDb");
            } catch (Throwable $cleanupError) {
                migrationLog("WARN  dry-run cleanup failed for $shadowDb: " . $cleanupError->getMessage(), true);
            }
        }
    }
}

Config::load(__DIR__ . '/../.env');

try {
    $mode = resolveMigrationMode();
    $files = listMigrationFiles();
    if (count($files) === 0) {
        throw new RuntimeException('No migration files found under db/migrations.');
    }

    if ($mode === 'dry-run') {
        runDryRun($files);
        return;
    }

    $pdo = Db::pdo();
    $applied = loadAppliedMigrations($pdo);

    if ($mode === 'plan') {
        runPlan($files, $applied);
        return;
    }

    runApply($pdo, $files, $applied);
} catch (Throwable $e) {
    migrationLog('FAIL  ' . $e->getMessage(), true);
    if (PHP_SAPI === 'cli') {
        exit(1);
    }
    throw $e;
}
