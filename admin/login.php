<?php
/**
 * Admin login gate.
 *
 * Split-pane layout:
 *   - LEFT: branded hero (uses /assets/login/*.jpg image files if present,
 *           otherwise falls back to a CSS gradient).
 *   - RIGHT: login form with CSRF + lockout.
 *
 * On success -> redirects to ?next= target (defaults to /admin/index.php).
 */
declare(strict_types=1);

require_once __DIR__ . '/../src/Config.php';
require_once __DIR__ . '/includes/auth.php';

use RareFolio\Config;
use RareFolio\Auth;

Config::load(__DIR__ . '/../.env');
Auth::boot();

// Already signed in? Bounce to the destination.
if (Auth::isLoggedIn()) {
    $next = $_GET['next'] ?? '/admin/index.php';
    header('Location: ' . $next);
    exit;
}

$error   = null;
$user    = '';
$locked  = Auth::lockedOutUntil();
$next    = (string) ($_GET['next'] ?? $_POST['next'] ?? '/admin/index.php');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $locked === null) {
    $csrf = (string) ($_POST['csrf'] ?? '');
    if (!Auth::checkCsrf($csrf)) {
        $error = 'Session expired. Please try again.';
    } else {
        $user = trim((string) ($_POST['user'] ?? ''));
        $pass = (string) ($_POST['pass'] ?? '');
        if (Auth::attempt($user, $pass)) {
            // Safe redirect — only relative paths under /admin/
            if (!preg_match('#^/admin/[A-Za-z0-9_./?=&-]*$#', $next)) {
                $next = '/admin/index.php';
            }
            header('Location: ' . $next);
            exit;
        }
        $error  = 'Invalid credentials.';
        $locked = Auth::lockedOutUntil();
    }
}

$csrfToken = Auth::csrfToken();

// Optional brand images — drop any of these files in and they'll render.
$heroCandidates = [
    '/assets/login/hero.jpg',
    '/assets/login/hero.png',
    '/assets/login/hero.webp',
];
$logoCandidates = [
    '/assets/login/logo.png',
    '/assets/login/logo.svg',
    '/assets/logo.png',
];
$tilesDir = __DIR__ . '/../assets/login/tiles';
$tiles = is_dir($tilesDir)
    ? array_values(array_filter(
        glob($tilesDir . '/*.{jpg,jpeg,png,webp}', GLOB_BRACE) ?: [],
        'is_file'
    ))
    : [];
$tiles = array_map(
    static fn($p) => '/assets/login/tiles/' . basename($p),
    $tiles
);

$existingHero = null;
foreach ($heroCandidates as $rel) {
    if (is_file(__DIR__ . '/..' . $rel)) {
        $existingHero = $rel;
        break;
    }
}
$existingLogo = null;
foreach ($logoCandidates as $rel) {
    if (is_file(__DIR__ . '/..' . $rel)) {
        $existingLogo = $rel;
        break;
    }
}

function eh(?string $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Sign in · RareFolio Admin</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <link rel="stylesheet" href="/assets/admin.css">
</head>
<body class="rf-login-body">
<div class="rf-login-wrap">

    <aside class="rf-login-hero"
           <?php if ($existingHero): ?>
               style="background-image:url('<?= eh($existingHero) ?>');"
           <?php endif; ?>>
        <div class="rf-login-hero-overlay">
            <div class="rf-login-brand">
                <?php if ($existingLogo): ?>
                    <img src="<?= eh($existingLogo) ?>" alt="RareFolio" class="rf-login-logo">
                <?php else: ?>
                    <div class="rf-login-wordmark">RareFolio</div>
                <?php endif; ?>
                <div class="rf-login-tagline">Curated Cardano collector platform</div>
            </div>

            <?php if ($tiles): ?>
                <div class="rf-login-tiles">
                    <?php foreach (array_slice($tiles, 0, 6) as $t): ?>
                        <div class="rf-login-tile" style="background-image:url('<?= eh($t) ?>');"></div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="rf-login-tiles rf-login-tiles-placeholder">
                    <?php for ($i = 0; $i < 6; $i++): ?>
                        <div class="rf-login-tile"></div>
                    <?php endfor; ?>
                </div>
            <?php endif; ?>

            <footer class="rf-login-hero-foot">
                <small>Phase 1 · Token Registry</small>
            </footer>
        </div>
    </aside>

    <main class="rf-login-card">
        <h1 class="rf-login-title">Sign in</h1>
        <p class="rf-login-sub">Admin access to the RareFolio mint pipeline.</p>

        <?php if ($locked !== null): ?>
            <div class="rf-alert rf-alert-error">
                Too many failed attempts. Try again in
                <?= max(1, (int) ceil(($locked - time()) / 60)) ?> minute(s).
            </div>
        <?php elseif ($error): ?>
            <div class="rf-alert rf-alert-error"><?= eh($error) ?></div>
        <?php endif; ?>

        <form method="post" class="rf-form rf-login-form" autocomplete="off">
            <input type="hidden" name="csrf" value="<?= eh($csrfToken) ?>">
            <input type="hidden" name="next" value="<?= eh($next) ?>">

            <div>
                <label>Username</label>
                <input type="text" name="user" value="<?= eh($user) ?>"
                       autocomplete="username" autofocus required
                       <?= $locked !== null ? 'disabled' : '' ?>>
            </div>

            <div>
                <label>Password</label>
                <input type="password" name="pass"
                       autocomplete="current-password" required
                       <?= $locked !== null ? 'disabled' : '' ?>>
            </div>

            <button class="rf-btn rf-login-btn" type="submit"
                    <?= $locked !== null ? 'disabled' : '' ?>>
                Sign in
            </button>
        </form>

        <footer class="rf-login-foot">
            <small class="rf-mono">
                Session-based · CSRF protected · Soft lockout after 6 failures
            </small>
        </footer>
    </main>
</div>
</body>
</html>
