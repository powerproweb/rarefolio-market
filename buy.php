<?php
/**
 * Public NFT purchase page.
 *
 * URL: /buy.php?token=qd-silver-0000705
 *
 * Shows the token artwork, description, price, and three ways to purchase:
 *   1. Connect Wallet (CIP-30) — sidecar builds unsigned tx, buyer signs
 *   2. Manual payment — copy the payment address and send ADA from any wallet
 *   3. I already paid — enter your tx hash to create an order
 */
declare(strict_types=1);

require_once __DIR__ . '/src/Config.php';
require_once __DIR__ . '/src/Db.php';

use RareFolio\Config;
use RareFolio\Db;

Config::load(__DIR__ . '/.env');

$tokenId = trim((string) ($_GET['token'] ?? ''));

// ------------------------------------------------------------------
// Look up the token
// ------------------------------------------------------------------
$token      = null;
$collection = null;
$dbError    = null;

if ($tokenId !== '') {
    try {
        $pdo = Db::pdo();

        $tStmt = $pdo->prepare(
            'SELECT t.*, c.name AS collection_name,
                    c.primary_sale_price_lovelace AS collection_price,
                    c.split_wallet_addr, c.split_wallet_env_key,
                    c.royalty_total_pct, c.platform_fee_pct
               FROM qd_tokens t
               LEFT JOIN qd_collections c ON c.slug = t.collection_slug
              WHERE t.rarefolio_token_id = ?
              LIMIT 1'
        );
        $tStmt->execute([$tokenId]);
        $token = $tStmt->fetch();

        // Check for a per-token listing price (overrides collection price)
        if ($token) {
            $lStmt = $pdo->prepare(
                "SELECT asking_price_lovelace FROM qd_listings
                  WHERE rarefolio_token_id = ? AND status = 'active'
                  LIMIT 1"
            );
            $lStmt->execute([$tokenId]);
            $listing = $lStmt->fetch();
            if ($listing && $listing['asking_price_lovelace'] !== null) {
                $token['price_lovelace'] = (int) $listing['asking_price_lovelace'];
            } else {
                $token['price_lovelace'] = $token['collection_price'] !== null
                    ? (int) $token['collection_price']
                    : null;
            }
        }
    } catch (Throwable $e) {
        $dbError = $e->getMessage();
    }
}

// ------------------------------------------------------------------
// Helpers
// ------------------------------------------------------------------
function h(?string $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function ada(int $lovelace, int $decimals = 2): string
{
    return number_format($lovelace / 1_000_000, $decimals) . ' ₳';
}

function ipfsGateway(string $uri): string
{
    if (str_starts_with($uri, 'ipfs://')) {
        return 'https://gateway.pinata.cloud/ipfs/' . substr($uri, 7);
    }
    return $uri;
}

// Extract image from cip25_json (may be array after sanitize)
function extractImage(mixed $val): string
{
    if (is_array($val)) $val = implode('', $val);
    return is_string($val) ? $val : '';
}

$cip25    = [];
$imgUri   = '';
$descText = '';

if ($token) {
    $cip25    = json_decode((string)($token['cip25_json'] ?? '{}'), true) ?: [];
    $rawImg   = $cip25['image'] ?? '';
    $imgUri   = ipfsGateway(extractImage($rawImg));
    $rawDesc  = $cip25['description'] ?? '';
    $descText = is_array($rawDesc) ? implode(' ', $rawDesc) : (string)$rawDesc;
}

$isSold = $token && in_array($token['primary_sale_status'], ['sold', 'sold_pre_marketplace'], true);
$sidecarUrl = rtrim((string) Config::get('SIDECAR_BASE_URL', 'http://localhost:4000'), '/');
$mainSiteUrl = 'https://rarefolio.io';

$pageTitle = $token ? h($token['title']) . ' — RareFolio' : 'Purchase — RareFolio';
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
  .buy-grid     { display:grid; grid-template-columns:1fr 1fr; gap:2rem; max-width:1100px; margin:2rem auto; padding:0 1.5rem; }
  .buy-art      { border-radius:16px; overflow:hidden; }
  .buy-art img  { width:100%; height:auto; display:block; }
  .buy-panel    { display:flex; flex-direction:column; gap:1.5rem; }
  .buy-title    { font-size:1.6rem; font-weight:700; color:#ffefbd; margin:0; }
  .buy-char     { color:#a0aec0; font-size:1rem; margin:0.25rem 0 0; }
  .buy-price    { font-size:2rem; font-weight:700; color:#00d4e7; }
  .buy-desc     { color:#cbd5e0; line-height:1.7; }
  .buy-section  { background:rgba(255,255,255,0.04); border:1px solid rgba(255,255,255,0.1); border-radius:12px; padding:1.5rem; }
  .buy-section h3 { margin:0 0 1rem; font-size:1rem; text-transform:uppercase; letter-spacing:.08em; color:#a0aec0; }
  .addr-box     { background:#0a0d17; border:1px solid #2d3748; border-radius:8px; padding:.75rem 1rem; font-family:monospace; font-size:.8rem; word-break:break-all; color:#e2e8f0; }
  .copy-btn     { margin-top:.5rem; background:transparent; border:1px solid #4a5568; color:#a0aec0; padding:.4rem .9rem; border-radius:6px; cursor:pointer; font-size:.8rem; }
  .copy-btn:hover { border-color:#00d4e7; color:#00d4e7; }
  .pay-status   { margin-top:.75rem; font-size:.85rem; min-height:1.2em; }
  .rf-input     { width:100%; background:#0a0d17; border:1px solid #2d3748; border-radius:8px; padding:.6rem .9rem; color:#e2e8f0; font-size:.9rem; box-sizing:border-box; }
  .rf-input:focus { outline:none; border-color:#00d4e7; }
  .btn-primary  { background:#00d4e7; color:#000; border:none; border-radius:8px; padding:.75rem 1.75rem; font-weight:700; font-size:1rem; cursor:pointer; width:100%; }
  .btn-primary:hover { background:#00eeff; }
  .btn-primary:disabled { background:#2d3748; color:#718096; cursor:not-allowed; }
  .sold-badge   { display:inline-block; background:#e53e3e; color:#fff; border-radius:8px; padding:.5rem 1.5rem; font-weight:700; font-size:1.1rem; }
  .step-num     { display:inline-flex; width:1.6rem; height:1.6rem; border-radius:50%; background:#00d4e7; color:#000; font-weight:700; font-size:.8rem; align-items:center; justify-content:center; margin-right:.5rem; }
  .alert-ok     { background:rgba(0,212,100,.15); border:1px solid rgba(0,212,100,.4); border-radius:8px; padding:1rem; color:#9ae6b4; }
  .alert-err    { background:rgba(229,62,62,.12); border:1px solid rgba(229,62,62,.4); border-radius:8px; padding:1rem; color:#fc8181; }
  @media(max-width:700px){ .buy-grid{grid-template-columns:1fr;} }
</style>
</head>
<body id="top">

<header class="topbar">
  <div class="container topbar-inner">
    <a class="brand" href="<?= h($mainSiteUrl) ?>">
      <img src="<?= h($mainSiteUrl) ?>/assets/img/rf_logo_site.png" alt="RareFolio">
      <div class="title mas_txt_clr"><strong>RareFolio.io</strong><span>Purchase</span></div>
    </a>
    <a class="btn primary" href="<?= h($mainSiteUrl) ?>/collections.html" style="margin-left:auto">← Collections</a>
  </div>
</header>

<main>
<?php if (!$tokenId || !$token): ?>
  <div style="text-align:center;padding:4rem 1rem;color:#a0aec0;">
    <h2>Token not found</h2>
    <p><?= $dbError ? h($dbError) : 'No token ID provided or the token does not exist.' ?></p>
    <a href="<?= h($mainSiteUrl) ?>/collections.html" class="btn primary">← Back to collections</a>
  </div>

<?php elseif ($isSold): ?>
  <div style="text-align:center;padding:4rem 1rem;">
    <?php if ($imgUri): ?>
      <img src="<?= h($imgUri) ?>" alt="<?= h($token['title']) ?>"
           style="max-width:360px;border-radius:16px;margin-bottom:1.5rem;display:block;margin-left:auto;margin-right:auto;">
    <?php endif; ?>
    <span class="sold-badge">SOLD — This piece has found its keeper.</span>
    <p style="color:#a0aec0;margin-top:1rem;"><?= h($token['title']) ?></p>
    <a href="<?= h($mainSiteUrl) ?>/collections.html" class="btn primary" style="display:inline-block;margin-top:1rem;">← View other pieces</a>
  </div>

<?php else: ?>

<div class="buy-grid">

  <!-- Artwork -->
  <div class="buy-art">
    <?php if ($imgUri && !str_contains($imgUri, 'REPLACE_WITH_CID')): ?>
      <img src="<?= h($imgUri) ?>" alt="<?= h($token['title']) ?>" loading="eager">
    <?php else: ?>
      <div style="background:#0a0d17;border-radius:16px;aspect-ratio:1;display:flex;align-items:center;justify-content:center;color:#4a5568;">
        <span>Artwork coming soon</span>
      </div>
    <?php endif; ?>
  </div>

  <!-- Purchase panel -->
  <div class="buy-panel">

    <div>
      <h1 class="buy-title"><?= h($token['title']) ?></h1>
      <?php if ($token['character_name']): ?>
        <p class="buy-char"><?= h($token['character_name']) ?></p>
      <?php endif; ?>
      <p style="color:#718096;font-size:.85rem;margin:.5rem 0 0">
        <?= h($token['collection_name'] ?? $token['collection_slug']) ?>
        &nbsp;·&nbsp; Edition <?= h($token['edition'] ?? '') ?>
        &nbsp;·&nbsp; <?= h($token['artist'] ?? '') ?>
      </p>
    </div>

    <?php if ($token['price_lovelace']): ?>
      <div class="buy-price"><?= ada((int)$token['price_lovelace']) ?></div>
    <?php else: ?>
      <div class="buy-price" style="font-size:1.2rem;color:#a0aec0;">Price on request</div>
    <?php endif; ?>

    <?php if ($descText): ?>
      <p class="buy-desc"><?= h($descText) ?></p>
    <?php endif; ?>

    <?php if (!$token['price_lovelace'] || !$token['split_wallet_addr']): ?>
      <!-- No price or no split wallet configured -->
      <div class="buy-section">
        <h3>How to purchase</h3>
        <p style="color:#cbd5e0;">This piece is available by inquiry. Contact us to arrange purchase.</p>
        <a href="<?= h($mainSiteUrl) ?>/contact.html" class="btn primary" style="display:inline-block;text-align:center;">
          Contact to Purchase
        </a>
      </div>

    <?php else: ?>

      <?php
        $priceLovelace   = (int)$token['price_lovelace'];
        $splitWalletAddr = (string)$token['split_wallet_addr'];
        $priceAda        = $priceLovelace / 1_000_000;
      ?>

      <!-- Option 1: CIP-30 Wallet Connect -->
      <div class="buy-section">
        <h3><span class="step-num">1</span> Connect &amp; Pay with Browser Wallet</h3>
        <p style="color:#a0aec0;font-size:.85rem;margin:0 0 1rem;">
          Works with Eternl, Lace, Nami, Typhon, and other CIP-30 wallets.
        </p>
        <button class="btn-primary" id="btn-connect-pay">
          Connect Wallet &amp; Pay <?= h(number_format($priceAda, 2)) ?> ₳
        </button>
        <div class="pay-status" id="wallet-status"></div>
        <pre id="wallet-log" style="display:none;background:#0a0d17;border-radius:8px;padding:.75rem;font-size:.75rem;color:#a0aec0;white-space:pre-wrap;margin-top:.75rem;max-height:120px;overflow:auto;"></pre>
      </div>

      <!-- Option 2: Manual payment -->
      <div class="buy-section">
        <h3><span class="step-num">2</span> Pay Manually from Any Wallet</h3>
        <p style="color:#a0aec0;font-size:.85rem;margin:0 0 .5rem;">
          Send exactly <strong style="color:#fff"><?= h(number_format($priceAda, 2)) ?> ₳</strong> to:
        </p>
        <div class="addr-box" id="payment-addr"><?= h($splitWalletAddr) ?></div>
        <button class="copy-btn" onclick="copyAddr()">Copy address</button>
        <p style="color:#718096;font-size:.8rem;margin:.5rem 0 0;">
          Include a note or message in your transaction if possible, or save your transaction hash.
          Then use option 3 below to confirm.
        </p>
      </div>

      <!-- Option 3: I already paid -->
      <div class="buy-section">
        <h3><span class="step-num">3</span> I've Already Sent Payment</h3>
        <div id="order-form">
          <div style="margin-bottom:.75rem">
            <label style="font-size:.85rem;color:#a0aec0;display:block;margin-bottom:.3rem">Your Cardano wallet address (where to send the NFT)</label>
            <input class="rf-input" type="text" id="field-buyer-addr" placeholder="addr1...">
          </div>
          <div style="margin-bottom:1rem">
            <label style="font-size:.85rem;color:#a0aec0;display:block;margin-bottom:.3rem">Transaction hash of your payment</label>
            <input class="rf-input" type="text" id="field-tx-hash" placeholder="64-character hex">
          </div>
          <button class="btn-primary" id="btn-confirm-order">Confirm my order</button>
          <div id="order-status" style="margin-top:.75rem"></div>
        </div>
        <div id="order-success" style="display:none"></div>
      </div>

    <?php endif; ?>

    <!-- Provenance & chain info -->
    <?php if ($token['policy_id'] && $token['policy_id'] !== str_repeat('0', 56)): ?>
    <details style="color:#4a5568;font-size:.8rem;">
      <summary style="cursor:pointer;color:#718096">On-chain details</summary>
      <div style="padding:.75rem 0;line-height:2;">
        Policy: <span style="color:#a0aec0;font-family:monospace"><?= h(substr($token['policy_id'],0,16).'…') ?></span><br>
        Asset: <span style="color:#a0aec0;font-family:monospace"><?= h(substr($token['asset_name_hex']??'',0,20).'…') ?></span><br>
        Network: <span style="color:#a0aec0"><?= h(Config::get('BLOCKFROST_NETWORK','preprod')) ?></span>
      </div>
    </details>
    <?php endif; ?>

  </div>
</div>

<?php endif; // not sold ?>
</main>

<?php if ($token && !$isSold && $token['price_lovelace'] && $token['split_wallet_addr']): ?>
<script>
const TOKEN_ID       = <?= json_encode($tokenId) ?>;
const PRICE_LOVELACE = <?= (int)$token['price_lovelace'] ?>;
const SPLIT_ADDR     = <?= json_encode($token['split_wallet_addr']) ?>;
const ORDER_API      = '/api/buy-order.php';
const SIDECAR_PROXY  = '/admin/sidecar-proxy.php';  // proxies sidecar calls
const SIDECAR_BASE   = <?= json_encode($sidecarUrl) ?>;

// Copy address
function copyAddr() {
    navigator.clipboard.writeText(SPLIT_ADDR).then(() => {
        const btn = document.querySelector('.copy-btn');
        if (btn) { btn.textContent = 'Copied!'; setTimeout(() => btn.textContent = 'Copy address', 2000); }
    });
}

// --- CIP-30 wallet connect + pay ---
async function pickWallet() {
    const cardano = window.cardano;
    if (!cardano) throw new Error('No CIP-30 wallet detected. Install Eternl, Lace, or Nami.');
    const keys = ['eternl','lace','nami','typhon','flint','yoroi'];
    for (const k of keys) {
        if (cardano[k]?.enable) return { key: k, api: await cardano[k].enable() };
    }
    const any = Object.keys(cardano).find(k => typeof cardano[k]?.enable === 'function');
    if (!any) throw new Error('No CIP-30 compatible wallet found.');
    return { key: any, api: await cardano[any].enable() };
}

function log(msg, el = 'wallet-log') {
    const el2 = document.getElementById(el);
    if (el2) { el2.style.display = 'block'; el2.textContent += msg + '\n'; }
}

function status(msg, ok = true, el = 'wallet-status') {
    const el2 = document.getElementById(el);
    if (el2) el2.innerHTML = `<span style="color:${ok ? '#68d391' : '#fc8181'}">${msg}</span>`;
}

document.getElementById('btn-connect-pay')?.addEventListener('click', async () => {
    const btn = document.getElementById('btn-connect-pay');
    btn.disabled = true;
    status('Connecting wallet…');
    document.getElementById('wallet-log').textContent = '';

    try {
        // 1. Connect wallet
        const { key, api } = await pickWallet();
        status(`Connected: ${key}. Fetching address…`);
        log(`Wallet: ${key}`);

        const usedAddrs = await api.getUsedAddresses();
        const buyerAddr = usedAddrs?.[0] || (await api.getChangeAddress());
        if (!buyerAddr) throw new Error('Could not get wallet address.');
        log(`Buyer addr (hex): ${buyerAddr.slice(0, 40)}…`);

        // 2. Ask sidecar to build the unsigned payment tx
        status('Building payment transaction…');
        const prepResp = await fetch(SIDECAR_PROXY + '?path=/payment/prepare', {
            method: 'POST',
            headers: {'Content-Type':'application/json'},
            body: JSON.stringify({
                buyer_addr:      buyerAddr,
                recipient_addr:  SPLIT_ADDR,
                amount_lovelace: PRICE_LOVELACE,
            }),
        });
        // Note: sidecar-proxy only supports GET; use direct call for POST
        // Fall back to direct sidecar for payment prepare (public sidecar endpoint)
        let prepData;
        if (prepResp.ok) {
            prepData = await prepResp.json();
        } else {
            // Direct call if proxy doesn't support POST
            const direct = await fetch(SIDECAR_BASE + '/payment/prepare', {
                method: 'POST',
                headers: {'Content-Type':'application/json'},
                body: JSON.stringify({
                    buyer_addr:      buyerAddr,
                    recipient_addr:  SPLIT_ADDR,
                    amount_lovelace: PRICE_LOVELACE,
                }),
            });
            prepData = await direct.json();
        }

        if (!prepData.cbor_hex) {
            throw new Error(prepData.message || prepData.error || 'Sidecar returned no cbor_hex');
        }
        log(`Unsigned tx built (${prepData.cbor_hex.length} chars)`);

        // 3. Sign
        status('Please sign the transaction in your wallet…');
        const signedTx = await api.signTx(prepData.cbor_hex, false);
        log('Transaction signed.');

        // 4. Submit
        status('Submitting to Cardano…');
        const txHash = await api.submitTx(signedTx);
        log(`Submitted. tx_hash: ${txHash}`);

        // 5. Create order
        status('Recording your order…');
        const bech32Buyer = buyerAddr.startsWith('addr')
            ? buyerAddr
            : `addr_hex_${buyerAddr.slice(0, 20)}`; // best-effort display; order stores hex

        const orderResp = await fetch(ORDER_API, {
            method: 'POST',
            headers: {'Content-Type':'application/json'},
            body: JSON.stringify({
                token_id:   TOKEN_ID,
                buyer_addr: buyerAddr,
                tx_hash:    txHash,
                amount_lovelace: PRICE_LOVELACE,
            }),
        });
        const orderData = await orderResp.json();
        if (!orderData.ok) throw new Error(orderData.error || 'Order creation failed.');

        status(`✓ Payment sent! Order #${orderData.order_id}`, true);
        document.getElementById('wallet-log').textContent +=
            `\nOrder ID: ${orderData.order_id}\nTx hash: ${txHash}\n\nRareFolio will mint your NFT to your wallet within 24 hours.`;

    } catch (e) {
        status('Error: ' + (e?.message ?? String(e)), false);
        log('ERROR: ' + (e?.message ?? String(e)));
    } finally {
        btn.disabled = false;
    }
});

// --- Manual confirm order ---
document.getElementById('btn-confirm-order')?.addEventListener('click', async () => {
    const btn       = document.getElementById('btn-confirm-order');
    const buyerAddr = document.getElementById('field-buyer-addr')?.value.trim();
    const txHash    = document.getElementById('field-tx-hash')?.value.trim();
    const statusEl  = document.getElementById('order-status');

    if (!buyerAddr || !buyerAddr.startsWith('addr')) {
        statusEl.innerHTML = '<span style="color:#fc8181">Please enter a valid Cardano address (starts with addr).</span>';
        return;
    }
    if (!txHash || !/^[0-9a-f]{64}$/i.test(txHash)) {
        statusEl.innerHTML = '<span style="color:#fc8181">Please enter a valid 64-character hex transaction hash.</span>';
        return;
    }

    btn.disabled = true;
    statusEl.innerHTML = '<span style="color:#a0aec0">Submitting…</span>';

    try {
        const resp = await fetch(ORDER_API, {
            method: 'POST',
            headers: {'Content-Type':'application/json'},
            body: JSON.stringify({
                token_id:        TOKEN_ID,
                buyer_addr:      buyerAddr,
                tx_hash:         txHash,
                amount_lovelace: PRICE_LOVELACE,
            }),
        });
        const data = await resp.json();
        if (data.ok) {
            document.getElementById('order-form').style.display = 'none';
            document.getElementById('order-success').style.display = 'block';
            document.getElementById('order-success').innerHTML = `
                <div class="alert-ok">
                    <strong>Order received! Order #${data.order_id}</strong><br>
                    We will verify your payment and mint your NFT to:<br>
                    <span style="font-family:monospace;font-size:.8rem">${buyerAddr.slice(0,30)}…</span><br><br>
                    <em>This typically happens within 24 hours. Save your order number.</em>
                </div>`;
        } else {
            statusEl.innerHTML = `<span style="color:#fc8181">Error: ${data.error || 'Unknown error'}</span>`;
        }
    } catch (e) {
        statusEl.innerHTML = `<span style="color:#fc8181">Network error: ${e?.message}</span>`;
    } finally {
        btn.disabled = false;
    }
});
</script>
<?php endif; ?>
</body>
</html>
