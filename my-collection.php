<?php
/**
 * My Collection — collector portfolio page.
 *
 * URL: /my-collection.php
 *
 * Collector connects their Cardano wallet via CIP-30.
 * Page queries the marketplace database for all RareFolio pieces
 * currently held by that wallet and displays them gallery-style.
 */
declare(strict_types=1);

require_once __DIR__ . '/src/Config.php';

use RareFolio\Config;
Config::load(__DIR__ . '/.env');

$mainSiteUrl = 'https://rarefolio.io';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>My Collection | RareFolio</title>
<meta name="description" content="View your RareFolio CNFT collection. Connect your Cardano wallet to see all pieces you own with provenance and on-chain details.">
<link rel="icon" href="<?= $mainSiteUrl ?>/assets/img/rf_logo_site.png">
<link rel="stylesheet" href="<?= $mainSiteUrl ?>/assets/css/styles.css?v=20260412">
<style>
  /* ── Layout ─────────────────────────────────────────── */
  .mc-wrap     { max-width:1100px; margin:0 auto; padding:2rem 1.5rem 5rem; }

  /* ── Connect hero ───────────────────────────────────── */
  .mc-hero     { text-align:center; padding:4rem 1rem 3rem; }
  .mc-eyebrow  { font-size:.75rem; letter-spacing:.14em; text-transform:uppercase;
                 color:#718096; margin:0 0 1rem; }
  .mc-headline { font-size:2.6rem; font-weight:700; color:#ffefbd; margin:0 0 .75rem;
                 font-family:'Cormorant Garamond',Georgia,serif; line-height:1.15; }
  .mc-sub      { color:#a0aec0; font-size:1rem; max-width:460px; margin:0 auto 2rem; }

  /* ── Connect button ─────────────────────────────────── */
  .mc-connect-btn {
    display:inline-flex; align-items:center; gap:.65rem;
    background:linear-gradient(135deg,#00d4e7,#0096b4);
    color:#000; font-weight:700; font-size:1rem; border:none;
    border-radius:10px; padding:.9rem 2.25rem; cursor:pointer;
    transition:filter .2s; }
  .mc-connect-btn:hover { filter:brightness(1.12); }
  .mc-connect-btn:disabled { background:#2d3748; color:#4a5568; cursor:not-allowed; }
  .mc-wallet-icon { font-size:1.2rem; }

  /* ── Status bar ─────────────────────────────────────── */
  .mc-status   { text-align:center; margin:1rem 0 2rem; font-size:.9rem;
                 color:#718096; min-height:1.4em; }
  .mc-status.connected { color:#68d391; }
  .mc-status.error     { color:#fc8181; }

  /* ── Disconnect ─────────────────────────────────────── */
  .mc-disconnect { background:none; border:1px solid #2d3748; color:#718096;
                   padding:.35rem .85rem; border-radius:6px; cursor:pointer;
                   font-size:.8rem; margin-top:.5rem; }
  .mc-disconnect:hover { border-color:#4a5568; color:#a0aec0; }

  /* ── Grid ───────────────────────────────────────────── */
  .mc-grid     { display:grid;
                 grid-template-columns:repeat(auto-fill, minmax(300px,1fr));
                 gap:1.5rem; margin-top:1.5rem; }

  /* ── Card ───────────────────────────────────────────── */
  .mc-card     { background:rgba(255,255,255,.04); border:1px solid rgba(255,255,255,.09);
                 border-radius:16px; overflow:hidden;
                 transition:transform .2s, border-color .2s; }
  .mc-card:hover { transform:translateY(-3px); border-color:rgba(0,212,231,.35); }
  .mc-card-img { aspect-ratio:1; background:#0a0d17; overflow:hidden; }
  .mc-card-img img { width:100%; height:100%; object-fit:cover; display:block; }
  .mc-card-img .mc-no-img { width:100%; height:100%; display:flex;
                             align-items:center; justify-content:center;
                             color:#2d3748; font-size:.85rem; }
  .mc-card-body { padding:1.25rem; }
  .mc-card-name { font-size:1.05rem; font-weight:700; color:#ffefbd;
                  font-family:'Cormorant Garamond',Georgia,serif; margin:0 0 .2rem; }
  .mc-card-char { color:#718096; font-size:.8rem; margin:0 0 .75rem; }
  .mc-card-meta { display:flex; justify-content:space-between; align-items:center;
                  font-size:.78rem; color:#4a5568; margin-bottom:.85rem; }
  .mc-card-badges { display:flex; gap:.4rem; flex-wrap:wrap; }
  .mc-badge    { display:inline-block; border-radius:999px; padding:.2rem .65rem;
                 font-size:.7rem; font-weight:600; letter-spacing:.04em; }
  .mc-badge-owned  { background:rgba(0,212,231,.15); color:#00d4e7;
                     border:1px solid rgba(0,212,231,.3); }
  .mc-badge-pending { background:rgba(236,201,75,.12); color:#ecc94b;
                      border:1px solid rgba(236,201,75,.3); }
  .mc-card-actions { display:flex; gap:.5rem; }
  .mc-card-actions a { flex:1; text-align:center; padding:.5rem; border-radius:7px;
                        font-size:.78rem; font-weight:600; text-decoration:none;
                        transition:background .2s; }
  .mc-btn-verify  { background:rgba(0,212,231,.1); color:#00d4e7; border:1px solid rgba(0,212,231,.25); }
  .mc-btn-verify:hover { background:rgba(0,212,231,.2); }
  .mc-btn-view    { background:rgba(255,255,255,.05); color:#a0aec0; border:1px solid rgba(255,255,255,.1); }
  .mc-btn-view:hover { background:rgba(255,255,255,.1); }

  /* ── Empty state ────────────────────────────────────── */
  .mc-empty    { text-align:center; padding:4rem 1rem; }
  .mc-empty h2 { color:#4a5568; font-size:1.2rem; margin:0 0 .5rem; }
  .mc-empty p  { color:#718096; font-size:.9rem; max-width:400px; margin:0 auto 1.5rem; }

  /* ── Spinner ────────────────────────────────────────── */
  .mc-spinner  { display:inline-block; width:20px; height:20px; border:2px solid #2d3748;
                 border-top-color:#00d4e7; border-radius:50%; animation:spin .7s linear infinite; }
  @keyframes spin { to { transform:rotate(360deg); } }

  /* ── Loading overlay ────────────────────────────────── */
  #mc-loading  { text-align:center; padding:3rem; display:none; }
  #mc-loading.visible { display:block; }

  @media(max-width:500px) { .mc-headline { font-size:2rem; } }
</style>
</head>
<body id="top">

<header class="topbar">
  <div class="container topbar-inner">
    <a class="brand" href="<?= $mainSiteUrl ?>">
      <img src="<?= $mainSiteUrl ?>/assets/img/rf_logo_site.png" alt="RareFolio">
      <div class="title mas_txt_clr"><strong>RareFolio.io</strong><span>My Collection</span></div>
    </a>
    <a class="btn primary" href="<?= $mainSiteUrl ?>/collections.html" style="margin-left:auto">
      Collections
    </a>
  </div>
</header>

<main>
<div class="mc-wrap">

  <!-- ── Connect hero (shown before connection) ─────── -->
  <div class="mc-hero" id="mc-hero">
    <p class="mc-eyebrow">Collector Portfolio</p>
    <h1 class="mc-headline">Your RareFolio<br>Collection</h1>
    <p class="mc-sub">
      Connect your Cardano wallet to see every RareFolio piece you own,
      with provenance, edition details, and on-chain verification.
    </p>
    <button class="mc-connect-btn" id="btn-connect">
      <span class="mc-wallet-icon">⬡</span>
      Connect Wallet
    </button>
    <div id="mc-status" class="mc-status"></div>
  </div>

  <!-- ── Loading ────────────────────────────────────── -->
  <div id="mc-loading">
    <div class="mc-spinner"></div>
    <p style="color:#718096;margin-top:1rem;font-size:.9rem;">Loading your collection…</p>
  </div>

  <!-- ── Collection grid (populated by JS) ──────────── -->
  <div id="mc-collection" style="display:none;">

    <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:1rem;margin-bottom:1.5rem;">
      <div>
        <h2 style="margin:0;font-size:1.3rem;color:#e2e8f0;">
          Your pieces <span id="mc-count" style="color:#718096;font-weight:400;font-size:1rem;"></span>
        </h2>
        <p id="mc-wallet-display" style="font-family:monospace;font-size:.78rem;color:#4a5568;margin:.25rem 0 0;"></p>
      </div>
      <button class="mc-disconnect" id="btn-disconnect">Disconnect</button>
    </div>

    <div class="mc-grid" id="mc-grid"></div>

    <!-- Empty state (shown by JS if no pieces found) -->
    <div class="mc-empty" id="mc-empty" style="display:none;">
      <h2>No RareFolio pieces found</h2>
      <p>
        We didn't find any RareFolio NFTs in this wallet on the
        <?= htmlspecialchars((string)Config::get('BLOCKFROST_NETWORK', 'preprod')) ?> network.
      </p>
      <a href="<?= $mainSiteUrl ?>/collections.html" class="btn primary">
        Browse collections
      </a>
    </div>

  </div>

</div>
</main>

<script>
const API       = '/api/my-collection.php';
const MAIN_SITE = <?= json_encode($mainSiteUrl) ?>;
const MARKET    = window.location.origin;

// ── CIP-30 helpers ──────────────────────────────────────────────────────────

async function pickWallet() {
    const cardano = window.cardano;
    if (!cardano) throw new Error('No Cardano wallet extension detected. Install Eternl, Lace, or Nami.');
    const preferred = ['eternl','lace','nami','typhon','flint','yoroi'];
    for (const k of preferred) {
        if (cardano[k]?.enable) return { key: k, api: await cardano[k].enable() };
    }
    const any = Object.keys(cardano).find(k => typeof cardano[k]?.enable === 'function');
    if (!any) throw new Error('No CIP-30 compatible wallet found.');
    return { key: any, api: await cardano[any].enable() };
}

// ── UI helpers ───────────────────────────────────────────────────────────────

function setStatus(msg, cls = '') {
    const el = document.getElementById('mc-status');
    el.textContent = msg;
    el.className = 'mc-status ' + cls;
}

function showLoading(on) {
    document.getElementById('mc-loading').className = on ? 'mc-loading visible' : 'mc-loading';
}

function imgOrPlaceholder(url) {
    if (!url || url.includes('REPLACE_WITH_CID')) {
        return `<div class="mc-no-img">Artwork coming soon</div>`;
    }
    return `<img src="${url}" alt="NFT artwork" loading="lazy">`;
}

function renderToken(t) {
    return `
    <div class="mc-card">
      <div class="mc-card-img">${imgOrPlaceholder(t.image_url)}</div>
      <div class="mc-card-body">
        <h3 class="mc-card-name">${escHtml(t.title)}</h3>
        ${t.character_name ? `<p class="mc-card-char">${escHtml(t.character_name)}</p>` : ''}
        <div class="mc-card-meta">
          <span>${escHtml(t.collection)}</span>
          <span>${escHtml(t.edition)}</span>
        </div>
        <div class="mc-card-badges" style="margin-bottom:.85rem;">
          <span class="mc-badge mc-badge-owned">✓ Owned</span>
          ${t.fingerprint ? `<span class="mc-badge" style="background:rgba(255,255,255,.05);color:#4a5568;border:1px solid rgba(255,255,255,.08);">asset1…${t.fingerprint.slice(-6)}</span>` : ''}
        </div>
        <div class="mc-card-actions">
          <a href="${MAIN_SITE}/verify.html?nft=${encodeURIComponent(t.cnft_id)}" class="mc-btn-verify" target="_blank" rel="noopener">Verify provenance</a>
          <a href="${MARKET}/buy.php?token=${encodeURIComponent(t.cnft_id)}" class="mc-btn-view" target="_blank" rel="noopener">View details</a>
        </div>
      </div>
    </div>`;
}

function renderPendingOrder(o) {
    return `
    <div class="mc-card" style="border-color:rgba(236,201,75,.25);">
      <div class="mc-card-img">${imgOrPlaceholder(o.image_url)}</div>
      <div class="mc-card-body">
        <h3 class="mc-card-name">${escHtml(o.title)}</h3>
        ${o.character_name ? `<p class="mc-card-char">${escHtml(o.character_name)}</p>` : ''}
        <div class="mc-card-meta">
          <span>${escHtml(o.collection)}</span>
          <span>${escHtml(o.edition)}</span>
        </div>
        <div class="mc-card-badges" style="margin-bottom:.85rem;">
          <span class="mc-badge mc-badge-pending">⧗ Minting…</span>
        </div>
        <p style="color:#718096;font-size:.78rem;margin:0 0 .75rem;">${escHtml(o.note)}</p>
        <div class="mc-card-actions">
          <a href="${MARKET}/order-status.php?order=${o.order_id}" class="mc-btn-verify">View order status</a>
        </div>
      </div>
    </div>`;
}

function escHtml(str) {
    return String(str || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

// ── Main connect flow ────────────────────────────────────────────────────────

let _walletKey = null;

document.getElementById('btn-connect').addEventListener('click', connectAndLoad);
document.getElementById('btn-disconnect').addEventListener('click', disconnect);

async function connectAndLoad() {
    const btn = document.getElementById('btn-connect');
    btn.disabled = true;
    setStatus('Connecting wallet…');

    try {
        const { key, api } = await pickWallet();
        _walletKey = key;
        setStatus(`Connected: ${key}. Fetching your collection…`, 'connected');

        // Collect all addresses CIP-30 knows about
        const [used, change] = await Promise.all([
            api.getUsedAddresses().catch(() => []),
            api.getChangeAddress().catch(() => null),
        ]);
        const all = [...(used || [])];
        if (change && !all.includes(change)) all.push(change);

        if (all.length === 0) {
            setStatus('Wallet returned no addresses — make sure it has been used on-chain.', 'error');
            btn.disabled = false;
            return;
        }

        // Display truncated wallet address
        const display = all[0];
        const displayStr = display.length > 20
            ? display.slice(0, 12) + '…' + display.slice(-8)
            : display;
        document.getElementById('mc-wallet-display').textContent = `Wallet: ${displayStr}`;

        // Fetch owned tokens
        showLoading(true);
        document.getElementById('mc-hero').style.display = 'none';

        const resp = await fetch(API, {
            method: 'POST',
            headers: {'Content-Type':'application/json'},
            body: JSON.stringify({ addresses: all }),
        });
        const data = await resp.json();

        showLoading(false);
        document.getElementById('mc-collection').style.display = 'block';

        if (!data.ok) throw new Error(data.error || 'API error');

        const total = (data.tokens?.length || 0) + (data.orders?.length || 0);
        document.getElementById('mc-count').textContent = total > 0 ? `(${total})` : '';

        const grid = document.getElementById('mc-grid');
        grid.innerHTML = '';

        if (total === 0) {
            document.getElementById('mc-empty').style.display = 'block';
            setStatus('');
        } else {
            document.getElementById('mc-empty').style.display = 'none';
            (data.tokens || []).forEach(t => {
                const div = document.createElement('div');
                div.innerHTML = renderToken(t);
                grid.appendChild(div.firstElementChild);
            });
            (data.orders || []).forEach(o => {
                const div = document.createElement('div');
                div.innerHTML = renderPendingOrder(o);
                grid.appendChild(div.firstElementChild);
            });
            setStatus(`Showing ${total} RareFolio piece${total !== 1 ? 's' : ''} from your wallet.`, 'connected');
        }

    } catch (e) {
        showLoading(false);
        document.getElementById('mc-hero').style.display = 'block';
        setStatus('Error: ' + (e?.message ?? String(e)), 'error');
    } finally {
        btn.disabled = false;
    }
}

function disconnect() {
    _walletKey = null;
    document.getElementById('mc-hero').style.display = 'block';
    document.getElementById('mc-collection').style.display = 'none';
    document.getElementById('mc-grid').innerHTML = '';
    document.getElementById('mc-empty').style.display = 'none';
    setStatus('');
}
</script>
</body>
</html>
