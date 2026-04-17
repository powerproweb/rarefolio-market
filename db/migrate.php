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
        fwrite(STDOUT, "skip  $name (already applied)\n");
        continue;
    }

    $sql = file_get_contents($file);
    if ($sql === false || trim($sql) === '') {
        fwrite(STDERR, "skip  $name (empty or unreadable)\n");
        continue;
    }

    try {
        $pdo->beginTransaction();
        $pdo->exec($sql);
        $stmt = $pdo->prepare('INSERT INTO schema_migrations (filename) VALUES (?)');
        $stmt->execute([$name]);
        $pdo->commit();
        fwrite(STDOUT, "ok    $name\n");
        $ran++;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        fwrite(STDERR, "FAIL  $name: {$e->getMessage()}\n");
        exit(1);
    }
}

fwrite(STDOUT, "\nDone. Applied $ran migration(s).\n");
