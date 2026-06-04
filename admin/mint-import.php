<?php
/**
 * Bulk CSV import — loads multiple tokens into qd_mint_queue in one go.
 *
 * CSV column format:
 *
 *   Required:
 *     rarefolio_token_id  — e.g. qd-silver-0000705
 *     collection_slug     — e.g. silverbar-01-founders
 *     asset_name_utf8     — on-chain name, max 64 bytes
 *     title               — display name
 *     artist              — creator
 *     edition             — e.g. 1/8
 *     image_ipfs          — ipfs://Qm... URI
 *
 *   Optional:
 *     policy_id           — 56-char hex (blank until policy is derived)
 *     character_name      — long character name / subtitle
 *     description         — each metadata string value must be <= 64 bytes
 *     mediaType           — default image/jpeg
 *     website             — https://...
 *
 *   Custom attributes (attr_* columns):
 *     attr_bar_serial     → attributes.bar_serial
 *     attr_block          → attributes.block
 *     attr_archetype      → attributes.archetype
 *     attr_anything       → attributes.anything
 *     (add as many attr_* columns as you like)
 *
 *   Custom top-level metadata (meta_* columns):
 *     meta_certification  → metadata.certification
 *     meta_provenance     → metadata.provenance
 *     (add as many meta_* columns as you like)
 *
 * Flow:
 *   Step 1 — Upload CSV (GET or no file)
 *   Step 2 — Parse + validate preview (POST with file)
 *   Step 3 — Confirm import (POST with confirmed_rows JSON)
 */
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/../src/Cip25/ImportRowParser.php';

use RareFolio\Cip25\Validator;
use RareFolio\Cip25\ImportRowParser;
use RareFolio\Auth;

// -----------------------------------------------------------------------
// Template download
// -----------------------------------------------------------------------
if (isset($_GET['download_template'])) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="mint-import-template.csv"');
    $cols = [
        'rarefolio_token_id','collection_slug','policy_id','asset_name_utf8',
        'title','character_name','edition','artist','description',
        'image_ipfs','mediaType','website',
        'attr_bar_serial','attr_block','attr_archetype',
        'attr_rarity','attr_material','attr_weight_oz',
        'meta_proof_manifest_uri','meta_evidence_public_url',
        'meta_certification','meta_provenance',
    ];
    $example = [
        'qd-silver-0000705','silverbar-01-founders','','qd-silver-0000705',
        'Founders #1','The Archivist — Keeper of the First Ledger','1/8','RareFolio',
        'Keeper of the First Ledger. Founder token for Block 88.',
        'ipfs://REPLACE_WITH_CID','image/jpeg','https://rarefolio.io',
        'E101837','88','Archivist',
        'Founder','Fine silver .999','100',
        'https://rarefolio.io/assets/img/collection/scnft_founders/manifest.json',
        'https://rarefolio.io/assets/img/collection/scnft_founders/master_sha256_hash_ipfs.md',
        '','',
    ];
    $out = fopen('php://output', 'w');
    fputcsv($out, $cols);
    fputcsv($out, $example);
    // Instructions row (starts with #)
    fputcsv($out, array_map(fn($c) => match(true) {
        $c === 'rarefolio_token_id' => '# Required. Unique token ID.',
        $c === 'asset_name_utf8'    => '# Required. On-chain name (max 64 bytes).',
        $c === 'image_ipfs'         => '# Required. Must start with ipfs://',
        $c === 'meta_proof_manifest_uri' => '# Required. Proof manifest URL (https:// or ipfs://).',
        $c === 'meta_evidence_public_url' => '# Required. Evidence URL (https:// or ipfs://).',
        str_starts_with($c, 'attr_') => '# Optional. Becomes attributes.' . substr($c, 5),
        str_starts_with($c, 'meta_') => '# Optional. Becomes top-level metadata field.',
        default => '# Optional.',
    }, $cols));
    fclose($out);
    exit;
}

// -----------------------------------------------------------------------
// Step 3 — Confirmed import
// -----------------------------------------------------------------------
$importResults = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirmed_rows'])) {
    $rows  = json_decode((string)($_POST['confirmed_rows'] ?? '[]'), true) ?: [];
    $admin = Auth::currentUser() ?? 'admin';
    $importResults = ['inserted' => 0, 'skipped' => 0, 'errors' => []];

    foreach ($rows as $row) {
        try {
            applyCollectionInvariantChecks($pdo, $row);
            if (!empty($row['errors']) && is_array($row['errors'])) {
                throw new RuntimeException('Collection contract validation failed: ' . implode(' | ', $row['errors']));
            }
            $tid  = (string)($row['rarefolio_token_id'] ?? '');
            $validation = ImportRowParser::validateConfirmedRow($row);
            if ($validation['valid'] !== true) {
                throw new RuntimeException('Metadata validation failed: ' . implode(' | ', $validation['errors']));
            }
            $asset = $row['asset'] ?? null;
            if (!is_array($asset)) {
                throw new RuntimeException('Invalid row payload: missing metadata asset object.');
            }
            $cip25Wrapped = Validator::wrap($row['policy_id'] ?: 'PENDING', $row['asset_name_utf8'], $asset);
            $pdo->prepare(
                "INSERT INTO qd_mint_queue
                    (rarefolio_token_id, collection_slug, policy_id, asset_name_hex,
                     title, character_name, edition, cip25_json, image_ipfs_cid,
                     status, created_by_admin)
                 VALUES (:tid, :coll, :pol, :ahex, :title, :cname, :ed, :js, :cid, 'draft', :admin)"
            )->execute([
                'tid'   => $tid,
                'coll'  => $row['collection_slug'],
                'pol'   => $row['policy_id'] ?: null,
                'ahex'  => bin2hex($row['asset_name_utf8']),
                'title' => $row['title'],
                'cname' => $row['character_name'] ?: null,
                'ed'    => $row['edition'] ?: null,
                'js'    => json_encode($cip25Wrapped, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'cid'   => extractCidImport($row['image_ipfs'] ?? ''),
                'admin' => $admin,
            ]);
            $importResults['inserted']++;
        } catch (Throwable $e) {
            $importResults['errors'][] = ($row['rarefolio_token_id'] ?? '?') . ': ' . $e->getMessage();
            $importResults['skipped']++;
        }
    }
}

// -----------------------------------------------------------------------
// Step 2 — Parse + validate uploaded file
// -----------------------------------------------------------------------
$preview = null;   // null = no file yet; array = preview rows
$parseError = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['csv_file']) && $importResults === null) {
    $file = $_FILES['csv_file'];
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $parseError = 'Upload error code ' . $file['error'];
    } else {
        $parsed = ImportRowParser::parseUploadedCsv($file['tmp_name']);
        $parseError = $parsed['parseError'];
        $preview = $parsed['preview'];
        if ($parseError !== null) {
            $preview = null;
        } else {
            foreach ($preview as &$previewRow) {
                applyCollectionInvariantChecks($pdo, $previewRow);
            }
            unset($previewRow);
        }
    }
}

// -----------------------------------------------------------------------
// Helpers
// -----------------------------------------------------------------------
function extractCidImport(string $ipfsUri): ?string
{
    if (preg_match('#^ipfs://([A-Za-z0-9/._-]+)#', $ipfsUri, $m)) return $m[1];
    return null;
}

/**
 * Applies DB-backed collection checks and fail-closed contract rules to one import row.
 *
 * @param array<string,mixed> $row
 */
function applyCollectionInvariantChecks(PDO $pdo, array &$row): void
{
    $errors = [];
    $warnings = [];
    $existingErrors = $row['errors'] ?? [];
    $existingWarnings = $row['warnings'] ?? [];
    if (is_array($existingErrors)) {
        $errors = array_values(array_filter($existingErrors, 'is_string'));
    }
    if (is_array($existingWarnings)) {
        $warnings = array_values(array_filter($existingWarnings, 'is_string'));
    }

    $asset = $row['asset'] ?? null;
    $attrs = is_array($asset) && isset($asset['attributes']) && is_array($asset['attributes']) && !array_is_list($asset['attributes'])
        ? $asset['attributes']
        : [];
    $barSerial = strtoupper(trim((string) ($attrs['bar_serial'] ?? '')));
    if ($barSerial === '') {
        $errors[] = 'attributes.bar_serial is required (set attr_bar_serial).';
    } elseif (!preg_match('/^[A-Z][0-9]{5,12}$/', $barSerial)) {
        $errors[] = 'attributes.bar_serial format is invalid (expected letter + digits, e.g. E101837).';
    }

    $proofManifest = trim((string) ((is_array($asset) ? ($asset['proof_manifest_uri'] ?? '') : '')));
    if ($proofManifest === '') {
        $errors[] = 'proof_manifest_uri is required (set meta_proof_manifest_uri).';
    } elseif (!preg_match('#^(https?://|ipfs://)#i', $proofManifest)) {
        $errors[] = 'proof_manifest_uri must start with https:// or ipfs://.';
    }

    $evidenceUrl = trim((string) ((is_array($asset) ? ($asset['evidence_public_url'] ?? '') : '')));
    if ($evidenceUrl === '') {
        $errors[] = 'evidence_public_url is required (set meta_evidence_public_url).';
    } elseif (!preg_match('#^(https?://|ipfs://)#i', $evidenceUrl)) {
        $errors[] = 'evidence_public_url must start with https:// or ipfs://.';
    }

    $collectionSlug = trim((string) ($row['collection_slug'] ?? ''));
    if ($collectionSlug !== '') {
        $stmt = $pdo->prepare('SELECT policy_env_key, policy_id FROM qd_collections WHERE slug = ? LIMIT 1');
        $stmt->execute([$collectionSlug]);
        $collection = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!is_array($collection)) {
            $errors[] = "collection_slug '{$collectionSlug}' does not exist in qd_collections.";
        } else {
            $policyEnvKey = strtoupper(trim((string) ($collection['policy_env_key'] ?? '')));
            if ($policyEnvKey === '') {
                $errors[] = "collection_slug '{$collectionSlug}' has no policy_env_key in qd_collections.";
            } elseif (!preg_match('/^[A-Z0-9_]+$/', $policyEnvKey)) {
                $errors[] = "collection_slug '{$collectionSlug}' has invalid policy_env_key '{$policyEnvKey}'.";
            } else {
                $row['policy_env_key'] = $policyEnvKey;
            }

            $collectionPolicyId = strtolower(trim((string) ($collection['policy_id'] ?? '')));
            $rowPolicyId = strtolower(trim((string) ($row['policy_id'] ?? '')));

            if ($collectionPolicyId !== '' && !preg_match('/^[0-9a-f]{56}$/', $collectionPolicyId)) {
                $errors[] = "collection_slug '{$collectionSlug}' has invalid policy_id in qd_collections.";
            }
            if ($rowPolicyId !== '' && !preg_match('/^[0-9a-f]{56}$/', $rowPolicyId)) {
                $errors[] = 'policy_id must be 56 hex chars (or left blank).';
            }
            if ($collectionPolicyId !== '' && $rowPolicyId !== '' && $collectionPolicyId !== $rowPolicyId) {
                $errors[] = "policy_id mismatch for '{$collectionSlug}' (row '{$rowPolicyId}' vs collection '{$collectionPolicyId}').";
            }
            if ($collectionPolicyId !== '' && $rowPolicyId === '') {
                $row['policy_id'] = $collectionPolicyId;
                $warnings[] = "policy_id auto-resolved from qd_collections for '{$collectionSlug}'.";
            }
        }
    }

    $errors = array_values(array_unique($errors));
    $warnings = array_values(array_unique($warnings));
    $row['errors'] = $errors;
    $row['warnings'] = $warnings;
    $row['status'] = match (true) {
        $errors !== [] => 'error',
        $warnings !== [] => 'warning',
        default => 'ok',
    };
}

/**
 * Parse one CSV data row into a validated preview entry.
 *
 * @param  array<string,string> $data
 * @return array<string,mixed>  {line, token_id, asset, errors, warnings, status, serialized}
 */
function parseImportRow(array $data, int $line): array
{
    return ImportRowParser::parsePreviewRow($data, $line);
}

$pageTitle = 'Bulk mint import — RareFolio admin';
require __DIR__ . '/includes/header.php';
?>

<div class="rf-toolbar">
    <a href="/admin/mint.php" class="rf-btn rf-btn-ghost">&larr; Mint queue</a>
    <div class="rf-spacer"></div>
    <a href="/admin/mint-import.php?download_template=1" class="rf-btn rf-btn-ghost">↓ Download CSV template</a>
</div>

<h1>Bulk mint import</h1>
<p class="rf-mono">Upload a CSV spreadsheet to load multiple tokens into the mint queue at once. Each valid row becomes a <code>draft</code> queue entry.</p>

<?php if ($importResults !== null): ?>
    <div class="rf-alert rf-alert-ok">
        Import complete &mdash; <?= $importResults['inserted'] ?> rows inserted, <?= $importResults['skipped'] ?> skipped.
        <a href="/admin/mint.php">View mint queue &rarr;</a>
    </div>
    <?php foreach ($importResults['errors'] as $e): ?>
        <div class="rf-alert rf-alert-error"><?= h($e) ?></div>
    <?php endforeach; ?>
<?php endif; ?>

<?php if ($parseError): ?>
    <div class="rf-alert rf-alert-error"><?= h($parseError) ?></div>
<?php endif; ?>

<!-- ------------------------------------------------------------------- -->
<!-- Step 1: Upload form (shown when no preview yet)                      -->
<!-- ------------------------------------------------------------------- -->
<?php if ($preview === null && $importResults === null): ?>

<div style="background:var(--rf-surface);border:1px solid var(--rf-border);border-radius:4px;padding:1.5rem;max-width:700px;margin-bottom:2rem;">
    <h2 style="margin-top:0">CSV column guide</h2>
    <table class="rf-table" style="font-size:0.8rem;">
        <thead><tr><th>Column</th><th>Required</th><th>Notes</th></tr></thead>
        <tbody>
        <tr><td class="rf-mono">rarefolio_token_id</td><td>Yes</td><td>Unique ID, e.g. <code>qd-silver-0000705</code></td></tr>
        <tr><td class="rf-mono">collection_slug</td><td>Yes</td><td>Matches <code>qd_tokens.collection_slug</code></td></tr>
        <tr><td class="rf-mono">asset_name_utf8</td><td>Yes</td><td>On-chain name, max 64 bytes</td></tr>
        <tr><td class="rf-mono">title</td><td>Yes</td><td>Display name</td></tr>
        <tr><td class="rf-mono">artist</td><td>Yes</td><td>Creator name</td></tr>
        <tr><td class="rf-mono">edition</td><td>Yes</td><td>e.g. <code>1/8</code></td></tr>
        <tr><td class="rf-mono">image_ipfs</td><td>Yes</td><td>Must start with <code>ipfs://</code></td></tr>
        <tr><td class="rf-mono">policy_id</td><td>No</td><td>56 hex chars; leave blank until derived</td></tr>
        <tr><td class="rf-mono">character_name</td><td>No</td><td>Subtitle / archetype label</td></tr>
        <tr><td class="rf-mono">description</td><td>No</td><td>Each metadata string must be 64 bytes or less</td></tr>
        <tr><td class="rf-mono">mediaType</td><td>No</td><td>Default: <code>image/jpeg</code></td></tr>
        <tr><td class="rf-mono">website</td><td>No</td><td>Full URL</td></tr>
        <tr><td class="rf-mono">attr_bar_serial</td><td>Yes</td><td>Maps to <code>attributes.bar_serial</code>, required for launch contract validation.</td></tr>
        <tr><td class="rf-mono">attr_*</td><td>No</td><td>Any additional <code>attr_foo</code> maps to <code>attributes.foo</code>.</td></tr>
        <tr><td class="rf-mono">meta_*</td><td>No</td><td>Any <code>meta_foo</code> → top-level metadata field <code>foo</code></td></tr>
        <tr><td class="rf-mono">meta_proof_manifest_uri</td><td>Yes</td><td>Proof manifest URL, must start with <code>https://</code> or <code>ipfs://</code></td></tr>
        <tr><td class="rf-mono">meta_evidence_public_url</td><td>Yes</td><td>Evidence URL, must start with <code>https://</code> or <code>ipfs://</code></td></tr>
        </tbody>
    </table>
</div>

<form method="post" enctype="multipart/form-data" class="rf-form" style="max-width:500px">
    <label>Upload CSV file</label>
    <input type="file" name="csv_file" accept=".csv,text/csv" required>
    <div class="rf-toolbar" style="margin-top:1rem">
        <button type="submit" class="rf-btn">Upload &amp; validate &rarr;</button>
    </div>
</form>

<?php endif; ?>

<!-- ------------------------------------------------------------------- -->
<!-- Step 2: Validation preview                                           -->
<!-- ------------------------------------------------------------------- -->
<?php if ($preview !== null && $importResults === null): ?>

<?php
$countOk   = count(array_filter($preview, fn($r) => $r['status'] === 'ok'));
$countWarn = count(array_filter($preview, fn($r) => $r['status'] === 'warning'));
$countErr  = count(array_filter($preview, fn($r) => $r['status'] === 'error'));
$validRows = array_filter($preview, fn($r) => $r['status'] !== 'error');
?>

<div class="rf-alert rf-alert-ok" style="margin-bottom:1rem">
    <?= count($preview) ?> rows parsed &mdash;
    <strong><?= $countOk ?> clean</strong>,
    <?= $countWarn ?> with warnings,
    <?= $countErr ?> with errors.
    <?php if ($countErr > 0): ?>
        Error rows will be skipped. Fix them in your spreadsheet and re-upload, or proceed with the <?= count($validRows) ?> valid rows below.
    <?php endif; ?>
</div>

<table class="rf-table" style="font-size:0.8rem;">
    <thead>
        <tr>
            <th>#</th>
            <th>Token ID</th>
            <th>Title</th>
            <th>Collection</th>
            <th>Edition</th>
            <th>Image</th>
            <th>Status</th>
            <th>Messages</th>
        </tr>
    </thead>
    <tbody>
    <?php foreach ($preview as $row): ?>
        <?php
        $rowColor = match($row['status']) {
            'error'   => 'background:rgba(220,50,50,0.08)',
            'warning' => 'background:rgba(220,160,0,0.08)',
            default   => '',
        };
        ?>
        <tr style="<?= $rowColor ?>">
            <td class="rf-mono"><?= (int)$row['line'] ?></td>
            <td class="rf-mono"><?= h($row['rarefolio_token_id']) ?></td>
            <td><?= h($row['title']) ?></td>
            <td class="rf-mono"><?= h($row['collection_slug']) ?></td>
            <td class="rf-mono"><?= h($row['edition']) ?></td>
            <td class="rf-mono" style="font-size:0.7rem">
                <?php if (str_starts_with($row['image_ipfs'], 'ipfs://')): ?>
                    <span style="color:var(--rf-ok)"><?= h(substr($row['image_ipfs'], 0, 30)) ?>&hellip;</span>
                <?php else: ?>
                    <span style="color:var(--rf-error)"><?= h(substr($row['image_ipfs'] ?: '(missing)', 0, 30)) ?></span>
                <?php endif; ?>
            </td>
            <td>
                <span class="rf-pill rf-pill-<?= $row['status'] === 'ok' ? 'confirmed' : ($row['status'] === 'warning' ? 'submitted' : 'failed') ?>">
                    <?= h($row['status']) ?>
                </span>
            </td>
            <td style="font-size:0.75rem">
                <?php foreach ($row['errors'] as $e): ?>
                    <div style="color:var(--rf-error)">&times; <?= h($e) ?></div>
                <?php endforeach; ?>
                <?php foreach ($row['warnings'] as $w): ?>
                    <div style="color:var(--rf-warn)">&bull; <?= h($w) ?></div>
                <?php endforeach; ?>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>

<?php if (!empty($validRows)): ?>
<form method="post" style="margin-top:1.5rem">
    <input type="hidden" name="confirmed_rows" value="<?= h(json_encode(array_values($validRows), JSON_UNESCAPED_UNICODE)) ?>">
    <div class="rf-toolbar">
        <button type="submit" class="rf-btn">
            Import <?= count($validRows) ?> valid row<?= count($validRows) !== 1 ? 's' : '' ?> as draft &rarr;
        </button>
        <a href="/admin/mint-import.php" class="rf-btn rf-btn-ghost">Start over</a>
    </div>
    <p class="rf-mono" style="font-size:0.8rem;margin-top:0.5rem;">
        Rows are added as <code>draft</code> status. Change each to <code>ready</code> in the mint queue when you\\'re ready to mint.
    </p>
</form>
<?php else: ?>
<div class="rf-alert rf-alert-error" style="margin-top:1rem">No valid rows to import. Fix the errors in your spreadsheet and re-upload.</div>
<p><a href="/admin/mint-import.php" class="rf-btn rf-btn-ghost">Start over</a></p>
<?php endif; ?>

<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>
