<?php
/**
 * Order confirmation — the Thank You page.
 *
 * URL: /order-status.php?order=42
 *
 * Shown after a collector completes a purchase on buy.php.
 * Displays order details, status timeline, and what happens next.
 */
declare(strict_types=1);

require_once __DIR__ . '/src/Config.php';
require_once __DIR__ . '/src/Db.php';

use RareFolio\Config;
use RareFolio\Db;

Config::load(__DIR__ . '/.env');

$orderId = (int) ($_GET['order'] ?? 0);
$order   = null;
$token   = null;

if ($orderId > 0) {
    try {
        $pdo = Db::pdo();

        $oStmt = $pdo->prepare(
            'SELECT o.*, t.title, t.character_name, t.edition, t.collection_slug,
                    t.cip25_json, t.artist,
                    c.name AS collection_name
               FROM qd_orders o
               LEFT JOIN qd_tokens t ON t.rarefolio_token_id = o.rarefolio_token_id
               LEFT JOIN qd_collections c ON c.slug = t.collection_slug
              WHERE o.id = ?
              LIMIT 1'
        );
        $oStmt->execute([$orderId]);
        $order = $oStmt->fetch();
    } catch (Throwable) {
        // DB not available — page still renders gracefully
    }
}

function h(?string $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function ada(int $lovelace): string
{
    return number_format($lovelace / 1_000_000, 2) . ' ₳';
}

function ipfsGateway(string $uri): string
{
    if (str_starts_with($uri, 'ipfs://')) {
        return 'https://gateway.pinata.cloud/ipfs/' . substr($uri, 7);
    }
    return $uri;
}

$imgUri   = '';
$descText = '';
if ($order && !empty($order['cip25_json'])) {
    $cip25   = json_decode($order['cip25_json'], true) ?: [];
    $rawImg  = $cip25['image'] ?? '';
    if (is_array($rawImg)) $rawImg = implode('', $rawImg);
    $imgUri  = $rawImg ? ipfsGateway($rawImg) : '';
    $rawDesc = $cip25['description'] ?? '';
    $descText = is_array($rawDesc) ? implode(' ', $rawDesc) : (string) $rawDesc;
}

// Status timeline steps
$statusSteps = [
    'submitted' => 1,
    'settled'   => 3,
    'failed'    => 0,
];
$currentStep = $statusSteps[$order['status'] ?? 'submitted'] ?? 1;

$mainSiteUrl = 'https://rarefolio.io';
$pageTitle = $order
    ? 'Thank You — Order #' . $orderId . ' | RareFolio'
    : 'Order Confirmation | RareFolio';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= $pageTitle ?></title>
<link rel="icon" href="<?= h($mainSiteUrl) ?>/assets/img/rf_logo_site.png">
<link rel="stylesheet" href="<?= h($mainSiteUrl) ?>/assets/css/styles.css?v=20260412">
<style>
  /* ── Layout ─────────────────────────────────────────── */
  .ty-wrap      { max-width:860px; margin:0 auto; padding:2.5rem 1.5rem 4rem; }
  .ty-hero      { text-align:center; padding:3rem 1rem 2rem; }
  .ty-badge     { display:inline-block; background:linear-gradient(135deg,#00d4e7,#00a8c4);
                  color:#000; font-weight:800; font-size:.75rem; letter-spacing:.12em;
                  text-transform:uppercase; border-radius:999px; padding:.4rem 1.2rem;
                  margin-bottom:1.25rem; }
  .ty-headline  { font-size:2.4rem; font-weight:700; color:#ffefbd; margin:0 0 .5rem;
                  font-family:'Cormorant Garamond',Georgia,serif; line-height:1.2; }
  .ty-sub       { color:#a0aec0; font-size:1.05rem; max-width:480px; margin:0 auto; }

  /* ── Order card ─────────────────────────────────────── */
  .ty-card      { background:rgba(255,255,255,0.04); border:1px solid rgba(255,255,255,.1);
                  border-radius:16px; overflow:hidden; margin:2rem 0; }
  .ty-card-grid { display:grid; grid-template-columns:280px 1fr; }
  .ty-card-img  { background:#0a0d17; }
  .ty-card-img img { width:100%; height:100%; object-fit:cover; display:block; }
  .ty-card-body { padding:2rem; display:flex; flex-direction:column; gap:1rem; }
  .ty-token-name { font-size:1.4rem; font-weight:700; color:#ffefbd;
                   font-family:'Cormorant Garamond',Georgia,serif; margin:0; }
  .ty-token-sub { color:#718096; font-size:.85rem; margin:.25rem 0 0; }
  .ty-desc      { color:#cbd5e0; font-size:.9rem; line-height:1.65; }
  .ty-dl        { display:grid; grid-template-columns:auto 1fr; gap:.35rem 1rem;
                  font-size:.85rem; align-items:baseline; }
  .ty-dt        { color:#718096; white-space:nowrap; }
  .ty-dd        { color:#e2e8f0; font-family:monospace; word-break:break-all; margin:0; }

  /* ── Status timeline ────────────────────────────────── */
  .ty-timeline  { display:flex; gap:0; margin:2.5rem 0 1rem; position:relative; }
  .ty-timeline::before {
    content:''; position:absolute; top:22px; left:22px; right:22px;
    height:2px; background:#1a202c; z-index:0;
  }
  .ty-step      { flex:1; display:flex; flex-direction:column; align-items:center;
                  gap:.5rem; position:relative; z-index:1; }
  .ty-dot       { width:44px; height:44px; border-radius:50%;
                  display:flex; align-items:center; justify-content:center;
                  font-size:1.1rem; font-weight:700; border:2px solid #2d3748;
                  background:#111827; transition:all .3s; }
  .ty-dot.done  { background:#00d4e7; border-color:#00d4e7; color:#000; }
  .ty-dot.active { background:#1a202c; border-color:#00d4e7; color:#00d4e7;
                   box-shadow:0 0 0 4px rgba(0,212,231,.15); }
  .ty-dot.pending { background:#111827; border-color:#2d3748; color:#4a5568; }
  .ty-step-lbl  { font-size:.72rem; text-align:center; line-height:1.3;
                  text-transform:uppercase; letter-spacing:.06em; }
  .ty-step.done .ty-step-lbl   { color:#00d4e7; }
  .ty-step.active .ty-step-lbl { color:#e2e8f0; }
  .ty-step.pending .ty-step-lbl { color:#4a5568; }

  /* ── What happens next ──────────────────────────────── */
  .ty-next      { background:rgba(0,212,231,.06); border:1px solid rgba(0,212,231,.2);
                  border-radius:12px; padding:1.5rem; margin:1.5rem 0; }
  .ty-next h3   { color:#00d4e7; margin:0 0 .75rem; font-size:.9rem;
                  text-transform:uppercase; letter-spacing:.08em; }
  .ty-next ol   { color:#cbd5e0; margin:0; padding-left:1.25rem; line-height:2; font-size:.9rem; }

  /* ── Save note ──────────────────────────────────────── */
  .ty-save      { background:rgba(255,255,255,.03); border:1px solid rgba(255,255,255,.07);
                  border-radius:10px; padding:1.25rem 1.5rem; font-size:.85rem;
                  color:#718096; display:flex; gap:1rem; align-items:flex-start; }
  .ty-save strong { color:#a0aec0; }

  /* ── CTA buttons ────────────────────────────────────── */
  .ty-cta       { display:flex; gap:1rem; flex-wrap:wrap; justify-content:center;
                  margin:2.5rem 0 0; }
  .ty-cta a     { padding:.75rem 2rem; border-radius:8px; font-weight:600;
                  font-size:.95rem; text-decoration:none; }
  .ty-btn-primary { background:#00d4e7; color:#000; }
  .ty-btn-primary:hover { background:#00eeff; }
  .ty-btn-ghost { border:1px solid #2d3748; color:#a0aec0; }
  .ty-btn-ghost:hover { border-color:#4a5568; color:#e2e8f0; }

  @media(max-width:640px) {
    .ty-card-grid { grid-template-columns:1fr; }
    .ty-card-img  { max-height:300px; overflow:hidden; }
    .ty-headline  { font-size:1.8rem; }
  }
</style>
</head>
<body id="top">

<header class="topbar">
  <div class="container topbar-inner">
    <a class="brand" href="<?= h($mainSiteUrl) ?>">
      <img src="<?= h($mainSiteUrl) ?>/assets/img/rf_logo_site.png" alt="RareFolio">
      <div class="title mas_txt_clr"><strong>RareFolio.io</strong><span>Order confirmed</span></div>
    </a>
  </div>
</header>

<main>
<div class="ty-wrap">

<?php if (!$order): ?>
  <!-- Order not found -->
  <div class="ty-hero">
    <div class="ty-badge">Order Confirmation</div>
    <h1 class="ty-headline">Order #<?= (int)$orderId ?: '—' ?></h1>
    <p class="ty-sub">
      <?php if ($orderId > 0): ?>
        We couldn't load the details for this order right now, but your payment has been received.
        Please save your order number and contact us if you need assistance.
      <?php else: ?>
        No order ID provided. If you just completed a purchase, please check the
        confirmation message for your order number.
      <?php endif; ?>
    </p>
  </div>

<?php else: ?>

  <!-- ── Hero ─────────────────────────────────── -->
  <div class="ty-hero">
    <div class="ty-badge">Order #<?= (int)$orderId ?></div>
    <h1 class="ty-headline">Thank you for your purchase.</h1>
    <p class="ty-sub">
      Your payment has been received. Your NFT will be minted and sent to
      your wallet — typically within 24 hours.
    </p>
  </div>

  <!-- ── Token card ────────────────────────────── -->
  <div class="ty-card">
    <div class="ty-card-grid">
      <div class="ty-card-img">
        <?php if ($imgUri && !str_contains($imgUri, 'REPLACE_WITH_CID')): ?>
          <img src="<?= h($imgUri) ?>" alt="<?= h($order['title'] ?? '') ?>">
        <?php else: ?>
          <div style="aspect-ratio:1;display:flex;align-items:center;justify-content:center;color:#2d3748;font-size:.8rem;">
            Artwork coming soon
          </div>
        <?php endif; ?>
      </div>
      <div class="ty-card-body">
        <div>
          <h2 class="ty-token-name"><?= h($order['title'] ?? 'RareFolio NFT') ?></h2>
          <?php if (!empty($order['character_name'])): ?>
            <p class="ty-token-sub"><?= h($order['character_name']) ?></p>
          <?php endif; ?>
          <p class="ty-token-sub" style="margin-top:.4rem;">
            <?= h($order['collection_name'] ?? $order['collection_slug'] ?? '') ?>
            <?php if (!empty($order['edition'])): ?>&nbsp;·&nbsp; Edition <?= h($order['edition']) ?><?php endif; ?>
          </p>
        </div>

        <?php if ($descText): ?>
          <p class="ty-desc"><?= h(mb_substr($descText, 0, 200)) ?><?= mb_strlen($descText) > 200 ? '…' : '' ?></p>
        <?php endif; ?>

        <dl class="ty-dl">
          <dt class="ty-dt">Order</dt>
          <dd class="ty-dd">#<?= (int)$orderId ?></dd>

          <dt class="ty-dt">Amount paid</dt>
          <dd class="ty-dd" style="font-family:inherit;font-weight:700;color:#00d4e7;">
            <?= ada((int)($order['sale_amount_lovelace'] ?? 0)) ?>
          </dd>

          <?php if (!empty($order['buyer_addr'])): ?>
            <dt class="ty-dt">Deliver to</dt>
            <dd class="ty-dd">
              <?php
                $addr = (string)$order['buyer_addr'];
                echo h(strlen($addr) > 20 ? substr($addr, 0, 14) . '…' . substr($addr, -8) : $addr);
              ?>
            </dd>
          <?php endif; ?>

          <?php if (!empty($order['order_tx_hash'])): ?>
            <dt class="ty-dt">Payment tx</dt>
            <dd class="ty-dd">
              <a href="https://cardanoscan.io/transaction/<?= h($order['order_tx_hash']) ?>"
                 target="_blank" rel="noopener"
                 style="color:#00d4e7;">
                <?= h(substr($order['order_tx_hash'], 0, 14)) ?>…
              </a>
            </dd>
          <?php endif; ?>
        </dl>
      </div>
    </div>
  </div>

  <!-- ── Status timeline ──────────────────────── -->
  <?php
    $steps = [
        1 => ['label' => "Payment\nReceived", 'icon' => '✓'],
        2 => ['label' => "Payment\nVerified",  'icon' => '◎'],
        3 => ['label' => "NFT\nMinted",        'icon' => '⬡'],
        4 => ['label' => "In Your\nWallet",    'icon' => '★'],
    ];
    $activeStep = match($order['status']) {
        'submitted' => 1,
        'settled'   => 3,
        'failed'    => 0,
        default     => 1,
    };
  ?>
  <div class="ty-timeline">
    <?php foreach ($steps as $n => $step): ?>
      <?php
        $cls = $n < $activeStep ? 'done' : ($n === $activeStep ? 'active' : 'pending');
      ?>
      <div class="ty-step <?= $cls ?>">
        <div class="ty-dot <?= $cls ?>"><?= $step['icon'] ?></div>
        <div class="ty-step-lbl"><?= nl2br(h($step['label'])) ?></div>
      </div>
    <?php endforeach; ?>
  </div>

  <!-- ── What happens next ────────────────────── -->
  <?php if ($order['status'] !== 'settled'): ?>
  <div class="ty-next">
    <h3>What happens next</h3>
    <ol>
      <li>We verify your payment on the Cardano blockchain (usually within a few minutes).</li>
      <li>Once verified, we mint your NFT directly to your wallet address above.</li>
      <li>You'll see it in your Cardano wallet (Eternl, Lace, Nami, etc.) within 24 hours.</li>
      <li>The NFT also appears verified on <strong>rarefolio.io</strong> with your provenance record.</li>
    </ol>
  </div>
  <?php else: ?>
  <div class="ty-next" style="background:rgba(0,212,100,.06);border-color:rgba(0,212,100,.2);">
    <h3 style="color:#68d391;">Your NFT has been delivered</h3>
    <ol>
      <li>Your NFT has been minted and sent to your wallet.</li>
      <li>Check your Cardano wallet — it should appear under your assets or NFTs tab.</li>
      <li>Visit <strong>rarefolio.io/verify</strong> to see your provenance record.</li>
    </ol>
  </div>
  <?php endif; ?>

  <!-- ── Save this page ───────────────────────── -->
  <div class="ty-save">
    <span style="font-size:1.3rem;flex-shrink:0">🔖</span>
    <div>
      <strong>Save this page.</strong> Bookmark this URL or note your order number
      <strong>#<?= (int)$orderId ?></strong>. If you have any questions about your order,
      contact us with this number and we can look up your purchase instantly.
    </div>
  </div>

<?php endif; ?>

  <!-- ── CTA buttons ──────────────────────────── -->
  <div class="ty-cta">
    <a href="<?= h($mainSiteUrl) ?>/collections.html" class="ty-btn-ghost">← View collections</a>
    <a href="<?= h($mainSiteUrl) ?>/verify.html" class="ty-btn-ghost">Verify ownership</a>
    <a href="<?= h($mainSiteUrl) ?>" class="ty-btn-primary">Return to RareFolio</a>
  </div>

</div>
</main>

</body>
</html>
