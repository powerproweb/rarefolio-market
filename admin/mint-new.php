<?php
/**
 * CIP-25 metadata builder form.
 *
 * Two modes:
 *   GET  — render the form
 *   POST — validate + enqueue into qd_mint_queue as status=draft (or =ready if valid)
 */
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

use RareFolio\Cip25\Validator;

$errors   = [];
$warnings = [];
$ok       = null;
$form = [
    'rarefolio_token_id' => '',
    'collection_slug'    => 'genesis',
    'asset_name_utf8'    => '',
    'policy_id'          => '',
    'title'              => '',
    'character_name'     => '',
    'artist'             => '',
    'edition'            => '',
    'image_ipfs'         => '',
    'mediaType'          => 'image/png',
    'description'        => '',
    'website'            => '',
    'attributes_json'    => '{}',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach ($form as $k => $_) {
        $form[$k] = trim((string) ($_POST[$k] ?? ''));
    }

    // Parse attributes JSON
    $attributes = null;
    if ($form['attributes_json'] !== '') {
        $attributes = json_decode($form['attributes_json'], true);
        if (!is_array($attributes)) {
            $errors[] = '`attributes` must be valid JSON object (e.g. {"rarity":"rare","element":"fire"}).';
            $attributes = [];
        }
    }

    // Build the inner asset object
    $asset = array_filter([
        'name'               => $form['title'],
        'image'              => $form['image_ipfs'],
        'mediaType'          => $form['mediaType'],
        'description'        => $form['description'] !== '' ? $form['description'] : null,
        'artist'             => $form['artist'],
        'edition'            => $form['edition'],
        'attributes'         => $attributes ?: null,
        'rarefolio_token_id' => $form['rarefolio_token_id'],
        'collection'         => $form['collection_slug'],
        'website'            => $form['website'] !== '' ? $form['website'] : null,
    ], static fn($v) => $v !== null && $v !== '');

    $result = Validator::validate($asset);
    $errors   = array_merge($errors, $result['errors']);
    $warnings = $result['warnings'];

    // Extra checks specific to saving into the queue
    if ($form['rarefolio_token_id'] === '') {
        $errors[] = 'rarefolio_token_id is required.';
    }
    if ($form['asset_name_utf8'] === '') {
        $errors[] = 'On-chain asset name (UTF-8) is required.';
    }
    if ($form['policy_id'] !== '' && !preg_match('/^[0-9a-f]{56}$/i', $form['policy_id'])) {
        $errors[] = 'policy_id must be a 56-char hex string (or left blank until policy exists).';
    }

    if ($errors === []) {
        try {
            $assetNameHex = bin2hex($form['asset_name_utf8']);
            $cip25Wrapped = Validator::wrap($form['policy_id'] ?: 'PENDING', $form['asset_name_utf8'], $asset);

            $stmt = $pdo->prepare(
                "INSERT INTO qd_mint_queue
                    (rarefolio_token_id, collection_slug, policy_id, asset_name_hex,
                     title, character_name, edition, cip25_json, image_ipfs_cid,
                     status, created_by_admin)
                 VALUES
                    (:tid, :coll, :pol, :ahex, :title, :cname, :ed, :js, :cid, 'ready', :admin)"
            );
            $stmt->execute([
                'tid'   => $form['rarefolio_token_id'],
                'coll'  => $form['collection_slug'],
                'pol'   => $form['policy_id'] ?: null,
                'ahex'  => $assetNameHex,
                'title' => $form['title'],
                'cname' => $form['character_name'] ?: null,
                'ed'    => $form['edition'] ?: null,
                'js'    => json_encode($cip25Wrapped, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'cid'   => extractCid($form['image_ipfs']),
                'admin' => $_SERVER['PHP_AUTH_USER'] ?? 'unknown',
            ]);
            $ok = 'Mint queued with status `ready`. ID = ' . $pdo->lastInsertId();
        } catch (Throwable $e) {
            $errors[] = 'DB error: ' . $e->getMessage();
        }
    }
}

function extractCid(string $ipfsUri): ?string
{
    if (preg_match('#^ipfs://([A-Za-z0-9]+)#', $ipfsUri, $m)) {
        return $m[1];
    }
    return null;
}

$pageTitle = 'New mint — RareFolio admin';
require __DIR__ . '/includes/header.php';
?>

<h1>New mint</h1>
<p class="rf-mono">Build a CIP-25 metadata record and enqueue it. Validation runs on save; warnings don't block.</p>

<?php if ($ok !== null): ?>
    <div class="rf-alert rf-alert-ok"><?= h($ok) ?> &mdash; <a href="/admin/mint.php">back to queue</a></div>
<?php endif; ?>

<?php foreach ($errors as $err): ?>
    <div class="rf-alert rf-alert-error"><?= h($err) ?></div>
<?php endforeach; ?>

<?php foreach ($warnings as $w): ?>
    <div class="rf-alert rf-alert-warn"><?= h($w) ?></div>
<?php endforeach; ?>

<form method="post" class="rf-form">

    <div style="display:grid; grid-template-columns: 1fr 1fr; gap:1rem;">
        <div>
            <label>rarefolio_token_id *</label>
            <input type="text" name="rarefolio_token_id" value="<?= h($form['rarefolio_token_id']) ?>" placeholder="RF-0001" required>
        </div>
        <div>
            <label>collection_slug *</label>
            <input type="text" name="collection_slug" value="<?= h($form['collection_slug']) ?>" required>
        </div>
    </div>

    <div style="display:grid; grid-template-columns: 1fr 1fr; gap:1rem;">
        <div>
            <label>policy_id (56 hex chars, or blank)</label>
            <input type="text" name="policy_id" value="<?= h($form['policy_id']) ?>">
        </div>
        <div>
            <label>on-chain asset_name (UTF-8) *</label>
            <input type="text" name="asset_name_utf8" value="<?= h($form['asset_name_utf8']) ?>" placeholder="RareFolioGenesis0001" required>
        </div>
    </div>

    <div>
        <label>title / display name *</label>
        <input type="text" name="title" value="<?= h($form['title']) ?>" required>
    </div>

    <div style="display:grid; grid-template-columns: 1fr 1fr; gap:1rem;">
        <div>
            <label>character_name</label>
            <input type="text" name="character_name" value="<?= h($form['character_name']) ?>">
        </div>
        <div>
            <label>artist *</label>
            <input type="text" name="artist" value="<?= h($form['artist']) ?>" required>
        </div>
    </div>

    <div style="display:grid; grid-template-columns: 1fr 1fr 1fr; gap:1rem;">
        <div>
            <label>edition *</label>
            <input type="text" name="edition" value="<?= h($form['edition']) ?>" placeholder="3/50" required>
        </div>
        <div>
            <label>mediaType</label>
            <input type="text" name="mediaType" value="<?= h($form['mediaType']) ?>">
        </div>
        <div>
            <label>website</label>
            <input type="url" name="website" value="<?= h($form['website']) ?>" placeholder="https://rarefolio.io/nft/...">
        </div>
    </div>

    <div>
        <label>image (ipfs:// URI) *</label>
        <input type="text" name="image_ipfs" value="<?= h($form['image_ipfs']) ?>" placeholder="ipfs://bafy..." required>
    </div>

    <div>
        <label>description</label>
        <textarea name="description"><?= h($form['description']) ?></textarea>
    </div>

    <div>
        <label>attributes (JSON object)</label>
        <textarea name="attributes_json"><?= h($form['attributes_json']) ?></textarea>
    </div>

    <div class="rf-toolbar">
        <button type="submit" class="rf-btn">Validate &amp; enqueue</button>
        <a href="/admin/mint.php" class="rf-btn rf-btn-ghost">Cancel</a>
    </div>
</form>

<?php require __DIR__ . '/includes/footer.php'; ?>
