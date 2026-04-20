<?php /** @var string $pageTitle */ ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title><?= h($pageTitle ?? 'RareFolio Admin') ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="/assets/admin.css">
</head>
<body>
<header class="rf-admin-header">
    <div class="rf-admin-brand">RareFolio <span class="rf-admin-env">admin</span></div>
    <nav class="rf-admin-nav">
        <a href="/admin/index.php">Overview</a>
        <a href="/admin/collections.php">Collections</a>
        <a href="/admin/mint.php">Mint queue</a>
        <a href="/admin/mint-new.php">New mint</a>
        <a href="/admin/asset-lookup.php">Asset lookup</a>
        <?php if (class_exists('RareFolio\\Auth') && \RareFolio\Auth::isLoggedIn()): ?>
            <span class="rf-admin-user"><?= h((string) \RareFolio\Auth::currentUser()) ?></span>
            <a href="/admin/logout.php" class="rf-admin-logout">Sign out</a>
        <?php endif; ?>
    </nav>
</header>
<main class="rf-admin-main">
