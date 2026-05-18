<?php
/**
 * Minimal schema migration runner.
 *
 * Reads every .sql file in db/migrations/ in lexical order and applies them
 * once. Records applied migrations in a `schema_migrations` table.
 *
 * Usage:  php db/migrate.php
 */
declare(strict_types=1);

require_once __DIR__ . '/../src/Config.php';
require_once __DIR__ . '/../src/Db.php';

use RareFolio\Config;
use RareFolio\Db;
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

Config::load(__DIR__ . '/../.env');
$pdo = Db::pdo();

$pdo->exec(
    "CREATE TABLE IF NOT EXISTS schema_migrations (
        filename  VARCHAR(191) NOT NULL PRIMARY KEY,
        applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);

$applied = $pdo->query('SELECT filename FROM schema_migrations')
               ->fetchAll(PDO::FETCH_COLUMN);
$applied = array_flip($applied);

$files = glob(__DIR__ . '/migrations/*.sql') ?: [];
sort($files, SORT_STRING);

$ran = 0;
foreach ($files as $file) {
    $name = basename($file);
    if (isset($applied[$name])) {
        migrationLog("skip  $name (already applied)");
        continue;
    }

    $sql = file_get_contents($file);
    if ($sql === false || trim($sql) === '') {
        migrationLog("skip  $name (empty or unreadable)", true);
        continue;
    }
    if (isOpsOnlyMigration($sql)) {
        migrationLog("skip  $name (ops-only migration)");
        continue;
    }
    if (hasDynamicSqlControlStatements($sql)) {
        $message = "FAIL  $name: contains dynamic SQL control statements. Mark this migration with '-- @ops_only' and execute manually.";
        migrationLog($message, true);
        if (PHP_SAPI === 'cli') {
            exit(1);
        }
        throw new RuntimeException($message);
    }
    if (hasUnresolvedPlaceholderGuard($sql)) {
        $message = "FAIL  $name: contains unresolved REPLACE_* placeholders in an auto-run migration.";
        migrationLog($message, true);
        if (PHP_SAPI === 'cli') {
            exit(1);
        }
        throw new RuntimeException($message);
    }

    try {
        $pdo->beginTransaction();
        $pdo->exec($sql);
        $stmt = $pdo->prepare('INSERT INTO schema_migrations (filename) VALUES (?)');
        $stmt->execute([$name]);
        // MySQL implicitly commits open transactions when DDL statements run
        // (CREATE TABLE / ALTER TABLE / etc.). Only commit if a transaction is
        // still active; otherwise the INSERT above has already auto-committed.
        if ($pdo->inTransaction()) {
            $pdo->commit();
        }
        migrationLog("ok    $name");
        $ran++;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $message = "FAIL  $name: {$e->getMessage()}";
        migrationLog($message, true);
        if (PHP_SAPI === 'cli') {
            exit(1);
        }
        throw new RuntimeException($message, 0, $e);
    }
}

migrationLog('');
migrationLog("Done. Applied $ran migration(s).");
