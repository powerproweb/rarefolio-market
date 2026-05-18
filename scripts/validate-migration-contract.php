<?php
declare(strict_types=1);

/**
 * Validate migration contract for deploy-safe auto-run migrations.
 *
 * Rules:
 * - Auto-run migrations MUST NOT contain dynamic SQL control statements:
 *     PREPARE, EXECUTE, DEALLOCATE PREPARE, SIGNAL SQLSTATE
 * - Auto-run migrations MUST NOT contain unresolved REPLACE_* placeholders
 * - One-off operational migrations MUST include "-- @ops_only" and are skipped
 *   by db/migrate.php.
 *
 * Usage:
 *   php scripts/validate-migration-contract.php
 */

function hasOpsOnlyMarker(string $sql): bool
{
    return preg_match('/^\s*--\s*@ops_only\b/im', $sql) === 1;
}

function hasDynamicSqlControlStatements(string $sql): bool
{
    return preg_match('/^\s*(PREPARE|DEALLOCATE\s+PREPARE|EXECUTE|SIGNAL\s+SQLSTATE)\b/im', $sql) === 1;
}

function hasUnresolvedPlaceholderGuard(string $sql): bool
{
    return preg_match("/^\s*SET\s+@[A-Za-z0-9_]+\s*:=\s*'REPLACE_[A-Z0-9_]+'\s*;/im", $sql) === 1;
}

$root = dirname(__DIR__);
$files = glob($root . '/db/migrations/*.sql') ?: [];
sort($files, SORT_STRING);

if (count($files) === 0) {
    fwrite(STDERR, "No migration files found under db/migrations.\n");
    exit(1);
}

$errors = [];
$checked = 0;

foreach ($files as $path) {
    $checked++;
    $name = basename($path);
    $sql = file_get_contents($path);
    if ($sql === false) {
        $errors[] = "$name: unreadable file";
        continue;
    }

    $opsOnly = hasOpsOnlyMarker($sql);
    $hasDynamic = hasDynamicSqlControlStatements($sql);
    $hasPlaceholder = hasUnresolvedPlaceholderGuard($sql);

    if (!$opsOnly && $hasDynamic) {
        $errors[] = "$name: dynamic SQL control statements are not allowed in auto-run migrations";
    }
    if (!$opsOnly && $hasPlaceholder) {
        $errors[] = "$name: unresolved REPLACE_* placeholders are not allowed in auto-run migrations";
    }
}

if (count($errors) > 0) {
    fwrite(STDERR, "Migration contract validation failed:\n");
    foreach ($errors as $error) {
        fwrite(STDERR, " - $error\n");
    }
    exit(1);
}

fwrite(STDOUT, "Migration contract validation passed ($checked files checked).\n");
exit(0);
