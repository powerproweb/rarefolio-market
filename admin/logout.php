<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';

\RareFolio\Auth::logout();
header('Location: /admin/login.php');
exit;
