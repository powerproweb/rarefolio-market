<?php
/**
 * Bootstrap for every admin page.
 *
 * Loads config, verifies basic-auth credentials from .env, exposes $pdo.
 * This is intentionally minimal for Phase 1 — replace with a real
 * auth system (Laravel Sanctum, passkeys, etc.) before going public.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../src/Config.php';
require_once __DIR__ . '/../../src/Db.php';
require_once __DIR__ . '/../../src/Blockfrost/Client.php';
require_once __DIR__ . '/../../src/Cip25/Validator.php';
require_once __DIR__ . '/../../src/Sidecar/Client.php';
require_once __DIR__ . '/auth.php';

use RareFolio\Config;
use RareFolio\Db;
use RareFolio\Auth;

Config::load(__DIR__ . '/../../.env');

// Session-based gate. If not logged in, redirect to /admin/login.php.
Auth::requireLogin();

$pdo = Db::pdo();

function h(?string $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
