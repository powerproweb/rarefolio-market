<?php
declare(strict_types=1);

function h(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$mainSiteUrl = 'https://rarefolio.io';
$apiListingsPath = '/api/v1/listings';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>RareFolio Market</title>
<meta name="description" content="RareFolio Market landing page with live listings from the public API.">
<link rel="icon" href="<?= h($mainSiteUrl) ?>/assets/img/rf_logo_site.png">
<link rel="stylesheet" href="<?= h($mainSiteUrl) ?>/assets/css/styles.css?v=20260412">
<style>
  .mk-wrap { max-width: 1200px; margin: 0 auto; padding: 1.25rem 1rem 3rem; }
  .mk-hero { margin: 1rem 0 1rem; }
  .mk-hero h1 { margin: 0 0 .6rem; color: #ffefbd; }
  .mk-hero p { margin: 0; color: #a0aec0; line-height: 1.65; }
  .mk-quick-layouts { display: flex; gap: .55rem; flex-wrap: wrap; margin-top: .9rem; }
  .mk-chip {
    border: 1px solid rgba(255,255,255,.18);
    border-radius: 999px;
    color: #d8dee9;
    padding: .28rem .78rem;
    text-decoration: none;
    font-size: .78rem;
    background: rgba(255,255,255,.03);
  }
  .mk-chip.active {
    border-color: rgba(0,212,231,.6);
    background: rgba(0,212,231,.16);
    color: #8ef3ff;
  }

  .mk-toolbar {
    display: grid;
    grid-template-columns: repeat(6, minmax(150px, 1fr));
    gap: .75rem;
    align-items: end;
  }
  .mk-toolbar label {
    display: flex;
    flex-direction: column;
    gap: .3rem;
    font-size: .8rem;
    color: #9aa3b2;
    text-transform: uppercase;
    letter-spacing: .04em;
  }
  .mk-toolbar select,
  .mk-toolbar input {
    width: 100%;
    background: #0a0d17;
    border: 1px solid #2d3748;
    border-radius: 8px;
    color: #e2e8f0;
    padding: .58rem .66rem;
    font-size: .9rem;
    box-sizing: border-box;
  }
  .mk-toolbar .mk-actions {
    display: flex;
    gap: .5rem;
    align-items: center;
  }

  #mk-status { margin: .9rem 0 .45rem; color: #7f8ba3; font-size: .92rem; }
  #mk-summary { margin: 0 0 .8rem; color: #9aa3b2; font-size: .84rem; }
  #mk-empty {
    border: 1px dashed rgba(255,255,255,.2);
    border-radius: 10px;
    color: #9aa3b2;
    padding: 1rem;
    text-align: center;
  }

  #mk-listings {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
    gap: .9rem;
  }
  .mk-card {
    border: 1px solid rgba(255,255,255,.1);
    border-radius: 14px;
    background: rgba(255,255,255,.03);
    overflow: hidden;
  }
  .mk-media {
    display: block;
    aspect-ratio: 1 / 1;
    background: #0a0d17;
  }
  .mk-media img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    display: block;
  }
  .mk-body { padding: .86rem .9rem .94rem; }
  .mk-token {
    margin: 0 0 .42rem;
    color: #00d4e7;
    font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, "Liberation Mono", monospace;
    font-size: .78rem;
  }
  .mk-title {
    margin: 0;
    color: #f8e9bf;
    font-size: 1.02rem;
    line-height: 1.3;
  }
  .mk-character {
    margin: .42rem 0 .62rem;
    color: #95a0b3;
    font-size: .84rem;
    line-height: 1.45;
  }
  .mk-meta {
    display: flex;
    flex-wrap: wrap;
    gap: .35rem;
    margin-bottom: .62rem;
  }
  .mk-badge {
    border-radius: 999px;
    border: 1px solid rgba(255,255,255,.2);
    padding: .2rem .52rem;
    font-size: .72rem;
    color: #a7b3c8;
    background: rgba(255,255,255,.05);
  }
  .mk-price {
    color: #88ffd4;
    font-weight: 700;
    margin-bottom: .7rem;
    font-size: .98rem;
  }
  .mk-actions-row {
    display: flex;
    gap: .45rem;
    flex-wrap: wrap;
  }
  .mk-actions-row a {
    text-decoration: none;
    display: inline-block;
  }

  body[data-layout="compact"] #mk-listings {
    grid-template-columns: 1fr;
  }
  body[data-layout="compact"] .mk-card {
    display: grid;
    grid-template-columns: 1fr auto;
    align-items: center;
    padding: .75rem .85rem;
  }
  body[data-layout="compact"] .mk-media {
    display: none;
  }
  body[data-layout="compact"] .mk-body {
    padding: 0;
    margin-right: .65rem;
  }
  body[data-layout="compact"] .mk-character {
    display: none;
  }

  body[data-layout="spotlight"] #mk-listings {
    grid-template-columns: repeat(auto-fill, minmax(240px, 1fr));
  }
  @media (min-width: 960px) {
    body[data-layout="spotlight"] #mk-listings .mk-card:first-child {
      grid-column: span 2;
      grid-row: span 2;
    }
    body[data-layout="spotlight"] #mk-listings .mk-card:first-child .mk-media {
      aspect-ratio: 4 / 3;
    }
  }

  body[data-layout="table"] #mk-listings {
    display: block;
  }
  .mk-table-wrap {
    border: 1px solid rgba(255,255,255,.14);
    border-radius: 12px;
    overflow: auto;
    background: rgba(255,255,255,.03);
  }
  .mk-table {
    width: 100%;
    border-collapse: collapse;
    min-width: 860px;
  }
  .mk-table th,
  .mk-table td {
    padding: .66rem .72rem;
    border-bottom: 1px solid rgba(255,255,255,.08);
    text-align: left;
    color: #d7dde8;
    font-size: .86rem;
    vertical-align: top;
  }
  .mk-table th {
    color: #9aa3b2;
    text-transform: uppercase;
    letter-spacing: .04em;
    font-size: .74rem;
    background: rgba(255,255,255,.03);
  }
  .mk-table .mono {
    font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, "Liberation Mono", monospace;
    color: #8eefff;
  }

  @media (max-width: 1100px) {
    .mk-toolbar { grid-template-columns: repeat(3, minmax(160px, 1fr)); }
  }
  @media (max-width: 760px) {
    .mk-toolbar { grid-template-columns: 1fr; }
  }
</style>
</head>
<body id="top" data-layout="gallery">

<header class="topbar">
  <div class="container topbar-inner">
    <a class="brand" href="/">
      <img src="<?= h($mainSiteUrl) ?>/assets/img/rf_logo_site.png" alt="RareFolio">
      <div class="title mas_txt_clr"><strong>RareFolio</strong><span>Market</span></div>
    </a>
    <a class="btn" href="/my-collection.php" style="margin-left:auto;">My Collection</a>
    <a class="btn primary" href="<?= h($mainSiteUrl) ?>/collections.html">Back to Rarefolio</a>
  </div>
</header>

<main class="mk-wrap">
  <section class="panel pad mk-hero">
    <h1>RareFolio Market</h1>
    <p>Live listings are loaded from <code><?= h($apiListingsPath) ?></code>. You can switch between four layouts: gallery, compact, spotlight, and table.</p>
    <div class="mk-quick-layouts" id="mk-layout-links"></div>
  </section>

  <section class="panel pad">
    <div class="mk-toolbar">
      <label>
        Layout
        <select id="opt-layout">
          <option value="gallery">Gallery</option>
          <option value="compact">Compact</option>
          <option value="spotlight">Spotlight</option>
          <option value="table">Table</option>
        </select>
      </label>
      <label>
        Collection
        <select id="opt-collection">
          <option value="all">All listings</option>
          <option value="founders">Founders only</option>
          <option value="non_founders">Non founders</option>
        </select>
      </label>
      <label>
        Sale format
        <select id="opt-format">
          <option value="all">All formats</option>
          <option value="fixed">Fixed</option>
          <option value="auction">Auction</option>
          <option value="offer_only">Offer only</option>
        </select>
      </label>
      <label>
        Sort
        <select id="opt-sort">
          <option value="updated_desc">Newest updates</option>
          <option value="price_asc">Price, low to high</option>
          <option value="price_desc">Price, high to low</option>
          <option value="title_asc">Title, A to Z</option>
        </select>
      </label>
      <label>
        Search
        <input id="opt-search" type="search" placeholder="Token, title, or character">
      </label>
      <div class="mk-actions">
        <button id="opt-refresh" class="btn" type="button">Refresh</button>
      </div>
    </div>

    <p id="mk-status">Loading live listings...</p>
    <p id="mk-summary"></p>
    <div id="mk-empty" hidden>No listings matched your current options.</div>
    <div id="mk-listings"></div>
  </section>
</main>

<script>
(function () {
  'use strict';

  var MAIN_SITE = <?= json_encode($mainSiteUrl, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
  var API_LISTINGS = <?= json_encode($apiListingsPath, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
  var PAGE_LIMIT = 100;
  var MAX_PAGES = 20;
  var LAYOUTS = ['gallery', 'compact', 'spotlight', 'table'];
  var state = { listings: [] };

  var elLayout = document.getElementById('opt-layout');
  var elCollection = document.getElementById('opt-collection');
  var elFormat = document.getElementById('opt-format');
  var elSort = document.getElementById('opt-sort');
  var elSearch = document.getElementById('opt-search');
  var elRefresh = document.getElementById('opt-refresh');
  var elStatus = document.getElementById('mk-status');
  var elSummary = document.getElementById('mk-summary');
  var elEmpty = document.getElementById('mk-empty');
  var elListings = document.getElementById('mk-listings');
  var elLayoutLinks = document.getElementById('mk-layout-links');

  function escapeHtml(value) {
    return String(value == null ? '' : value)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');
  }

  function normalizeLayout(layout) {
    var val = String(layout || '').toLowerCase().trim();
    return LAYOUTS.indexOf(val) >= 0 ? val : 'gallery';
  }

  function currentLayoutFromUrl() {
    var qp = new URLSearchParams(window.location.search);
    return normalizeLayout(qp.get('layout'));
  }

  function setLayout(layout, syncUrl) {
    var normalized = normalizeLayout(layout);
    if (elLayout.value !== normalized) {
      elLayout.value = normalized;
    }
    document.body.setAttribute('data-layout', normalized);
    try {
      window.localStorage.setItem('rf_market_layout', normalized);
    } catch (_) {}
    if (syncUrl) {
      var url = new URL(window.location.href);
      url.searchParams.set('layout', normalized);
      window.history.replaceState({}, '', url.toString());
    }
    renderLayoutLinks(normalized);
  }

  function renderLayoutLinks(activeLayout) {
    var html = LAYOUTS.map(function (layout) {
      var cls = layout === activeLayout ? 'mk-chip active' : 'mk-chip';
      var label = layout.charAt(0).toUpperCase() + layout.slice(1);
      var href = '?layout=' + encodeURIComponent(layout);
      return '<a class="' + cls + '" href="' + href + '" data-layout-link="' + layout + '">' + label + '</a>';
    }).join('');
    elLayoutLinks.innerHTML = html;
    elLayoutLinks.querySelectorAll('[data-layout-link]').forEach(function (linkEl) {
      linkEl.addEventListener('click', function (event) {
        event.preventDefault();
        var layout = event.currentTarget.getAttribute('data-layout-link');
        setLayout(layout, true);
        render();
      });
    });
  }

  function listingStatusLabel(value) {
    var map = {
      listed_fixed: 'Fixed price',
      listed_auction: 'Auction',
      offer_only: 'Offer only',
      none: 'Not listed'
    };
    var key = String(value || '').toLowerCase();
    return map[key] || (key || 'Unknown');
  }

  function saleFormatLabel(value) {
    var map = { fixed: 'Fixed', auction: 'Auction', offer_only: 'Offer only' };
    var key = String(value || '').toLowerCase();
    return map[key] || (key || 'Unknown');
  }

  function collectionLabel(slug) {
    var raw = String(slug || '').trim();
    if (!raw) return 'Unspecified';
    return raw.replace(/[-_]+/g, ' ');
  }

  function isFounders(row) {
    return /founders/i.test(String(row.collection || ''));
  }

  function safeTokenId(value) {
    return String(value || '').replace(/[^a-z0-9-]/gi, '');
  }

  function imageUrl(row) {
    if (isFounders(row)) {
      var token = safeTokenId(row.cnft_id);
      if (token) {
        return MAIN_SITE + '/assets/img/collection/scnft_founders/' + token + '.jpg';
      }
    }
    return MAIN_SITE + '/assets/img/nfts/sys/placeholder.jpg';
  }

  function certIdFromListing(row) {
    var tokenMatch = String(row && row.cnft_id ? row.cnft_id : '').match(/^qd-silver-(\d{7})$/i);
    if (!tokenMatch) return '';

    var barSerialRaw = String((row && row.bar_serial) || '').trim();
    var barSerial = barSerialRaw ? barSerialRaw.toUpperCase() : '';
    if (!barSerial) {
      var collection = String((row && row.collection) || '').toLowerCase();
      if (collection.indexOf('silverbar-01') >= 0 || collection.indexOf('founders') >= 0) {
        barSerial = 'E101837';
      } else if (collection.indexOf('silverbar-02') >= 0) {
        barSerial = 'E102528';
      } else if (collection.indexOf('silverbar-03') >= 0) {
        barSerial = 'P154829';
      }
    }
    if (!barSerial) return '';
    return 'QDCERT-' + barSerial + '-' + tokenMatch[1];
  }

  function verifyUrlForListing(row) {
    var params = new URLSearchParams();
    var certId = certIdFromListing(row);
    var cnftId = String(row && row.cnft_id ? row.cnft_id : '').trim();
    if (certId) params.set('cert', certId);
    if (cnftId) params.set('cnft', cnftId);
    var qp = params.toString();
    return MAIN_SITE + '/verify' + (qp ? ('?' + qp) : '');
  }

  function toNumber(value, fallback) {
    var n = Number(value);
    return Number.isFinite(n) ? n : fallback;
  }

  function formatAda(lovelace) {
    if (lovelace == null || lovelace === '') return 'Price on request';
    var raw = toNumber(lovelace, NaN);
    if (!Number.isFinite(raw)) return 'Price on request';
    var ada = raw / 1000000;
    return ada.toLocaleString(undefined, { minimumFractionDigits: 0, maximumFractionDigits: 6 }) + ' ₳';
  }

  function formatDate(value) {
    if (!value) return 'Unknown';
    var dt = new Date(value);
    if (Number.isNaN(dt.getTime())) return String(value);
    return dt.toLocaleString();
  }

  function dateEpoch(value) {
    var dt = new Date(value || '');
    return Number.isNaN(dt.getTime()) ? 0 : dt.getTime();
  }

  function dedupeListings(rows) {
    var map = new Map();
    rows.forEach(function (row) {
      var id = String(row && row.cnft_id ? row.cnft_id : '').trim();
      if (!id) return;
      if (!map.has(id)) {
        map.set(id, row);
      }
    });
    return Array.from(map.values());
  }

  function applyFilters(rows) {
    var collectionFilter = String(elCollection.value || 'all');
    var formatFilter = String(elFormat.value || 'all');
    var search = String(elSearch.value || '').trim().toLowerCase();

    return rows.filter(function (row) {
      if (collectionFilter === 'founders' && !isFounders(row)) return false;
      if (collectionFilter === 'non_founders' && isFounders(row)) return false;

      var saleFormat = String(row.sale_format || '').toLowerCase();
      if (formatFilter !== 'all' && saleFormat !== formatFilter) return false;

      if (search) {
        var haystack = [
          row.cnft_id,
          row.title,
          row.character_name,
          row.collection
        ].join(' ').toLowerCase();
        if (haystack.indexOf(search) === -1) return false;
      }

      return true;
    });
  }

  function sortRows(rows) {
    var mode = String(elSort.value || 'updated_desc');
    var copy = rows.slice();
    copy.sort(function (a, b) {
      if (mode === 'price_asc') {
        return toNumber(a.price_lovelace, Number.MAX_SAFE_INTEGER) - toNumber(b.price_lovelace, Number.MAX_SAFE_INTEGER);
      }
      if (mode === 'price_desc') {
        return toNumber(b.price_lovelace, -1) - toNumber(a.price_lovelace, -1);
      }
      if (mode === 'title_asc') {
        return String(a.title || '').localeCompare(String(b.title || ''));
      }
      return dateEpoch(b.updated_at) - dateEpoch(a.updated_at);
    });
    return copy;
  }

  function renderTable(rows) {
    var body = rows.map(function (row) {
      var cnft = escapeHtml(row.cnft_id || '');
      var title = escapeHtml(row.title || row.cnft_id || 'Untitled');
      var character = escapeHtml(row.character_name || '');
      var collection = escapeHtml(collectionLabel(row.collection));
      var sale = escapeHtml(saleFormatLabel(row.sale_format));
      var listingState = escapeHtml(listingStatusLabel(row.status && row.status.listing));
      var price = escapeHtml(formatAda(row.price_lovelace));
      var updated = escapeHtml(formatDate(row.updated_at));
      var buyUrl = '/buy.php?token=' + encodeURIComponent(row.cnft_id || '');
      var verifyUrl = verifyUrlForListing(row);
      return '<tr>'
        + '<td class="mono">' + cnft + '</td>'
        + '<td>' + title + (character ? '<div style="color:#9aa3b2;margin-top:.25rem;">' + character + '</div>' : '') + '</td>'
        + '<td>' + collection + '</td>'
        + '<td>' + sale + '</td>'
        + '<td>' + listingState + '</td>'
        + '<td>' + price + '</td>'
        + '<td>' + updated + '</td>'
        + '<td><a class="btn" href="' + buyUrl + '">View</a> <a class="btn" href="' + verifyUrl + '" target="_blank" rel="noopener">Verify</a></td>'
        + '</tr>';
    }).join('');

    return '<div class="mk-table-wrap"><table class="mk-table">'
      + '<thead><tr><th>Token</th><th>Title</th><th>Collection</th><th>Format</th><th>Listing</th><th>Price</th><th>Updated</th><th>Action</th></tr></thead>'
      + '<tbody>' + body + '</tbody>'
      + '</table></div>';
  }

  function renderCards(rows) {
    return rows.map(function (row) {
      var cnft = escapeHtml(row.cnft_id || '');
      var title = escapeHtml(row.title || row.cnft_id || 'Untitled');
      var character = escapeHtml(row.character_name || '');
      var collection = escapeHtml(collectionLabel(row.collection));
      var sale = escapeHtml(saleFormatLabel(row.sale_format));
      var listingState = escapeHtml(listingStatusLabel(row.status && row.status.listing));
      var price = escapeHtml(formatAda(row.price_lovelace));
      var updated = escapeHtml(formatDate(row.updated_at));
      var buyUrl = '/buy.php?token=' + encodeURIComponent(row.cnft_id || '');
      var verifyUrl = verifyUrlForListing(row);
      var imgSrc = escapeHtml(imageUrl(row));
      var fallback = escapeHtml(MAIN_SITE + '/assets/img/nfts/sys/placeholder.jpg');

      return '<article class="mk-card">'
        + '<a class="mk-media" href="' + buyUrl + '">'
        + '<img src="' + imgSrc + '" alt="' + title + '" loading="lazy" onerror="this.onerror=null;this.src=\'' + fallback + '\';">'
        + '</a>'
        + '<div class="mk-body">'
        + '<p class="mk-token">' + cnft + '</p>'
        + '<h3 class="mk-title">' + title + '</h3>'
        + (character ? '<p class="mk-character">' + character + '</p>' : '')
        + '<div class="mk-meta">'
        + '<span class="mk-badge">' + collection + '</span>'
        + '<span class="mk-badge">' + sale + '</span>'
        + '<span class="mk-badge">' + listingState + '</span>'
        + '</div>'
        + '<div class="mk-price">' + price + '</div>'
        + '<div class="mk-actions-row">'
        + '<a class="btn primary" href="' + buyUrl + '">Purchase</a>'
        + '<a class="btn" href="' + verifyUrl + '" target="_blank" rel="noopener">Verify</a>'
        + '</div>'
        + '<div style="margin-top:.58rem;color:#78839a;font-size:.78rem;">Updated: ' + updated + '</div>'
        + '</div>'
        + '</article>';
    }).join('');
  }

  function render() {
    var layout = normalizeLayout(elLayout.value);
    setLayout(layout, false);
    var filtered = sortRows(applyFilters(state.listings));

    if (!filtered.length) {
      elListings.innerHTML = '';
      elEmpty.hidden = false;
      elSummary.textContent = '0 of ' + state.listings.length + ' listings shown.';
      return;
    }

    elEmpty.hidden = true;
    elSummary.textContent = filtered.length + ' of ' + state.listings.length + ' listings shown.';

    if (layout === 'table') {
      elListings.innerHTML = renderTable(filtered);
      return;
    }

    elListings.innerHTML = renderCards(filtered);
  }

  async function fetchJson(url) {
    var response = await fetch(url, {
      method: 'GET',
      headers: { 'Accept': 'application/json' },
      cache: 'no-store'
    });
    var body = await response.json();
    if (!response.ok || !body || body.ok === false) {
      var errMsg = body && body.error && body.error.message
        ? body.error.message
        : ('HTTP ' + response.status);
      throw new Error(errMsg);
    }
    return body;
  }

  async function loadListings() {
    elStatus.textContent = 'Loading live listings...';
    elRefresh.disabled = true;
    try {
      var rows = [];
      var offset = 0;

      for (var page = 0; page < MAX_PAGES; page++) {
        var cacheBust = encodeURIComponent(String(Date.now()) + '-' + page);
        var url = API_LISTINGS + '?limit=' + PAGE_LIMIT + '&offset=' + offset + '&_=' + cacheBust;
        var payload = await fetchJson(url);
        var data = payload && payload.data ? payload.data : {};
        var listingRows = Array.isArray(data.listings) ? data.listings : [];
        rows.push.apply(rows, listingRows);

        var total = toNumber(data.total, rows.length);
        if (listingRows.length === 0 || rows.length >= total) {
          break;
        }
        offset += PAGE_LIMIT;
      }

      state.listings = dedupeListings(rows);
      render();
      elStatus.textContent = 'Live listings loaded successfully.';
    } catch (err) {
      elListings.innerHTML = '';
      elEmpty.hidden = false;
      elSummary.textContent = '';
      elStatus.textContent = 'Could not load /api/v1/listings: ' + (err && err.message ? err.message : 'Unknown error');
    } finally {
      elRefresh.disabled = false;
    }
  }

  function bindControls() {
    [elCollection, elFormat, elSort].forEach(function (selectEl) {
      selectEl.addEventListener('change', render);
    });
    elSearch.addEventListener('input', render);
    elLayout.addEventListener('change', function () {
      setLayout(elLayout.value, true);
      render();
    });
    elRefresh.addEventListener('click', loadListings);
  }

  function initialLayout() {
    var fromUrl = currentLayoutFromUrl();
    if (fromUrl && fromUrl !== 'gallery') {
      return fromUrl;
    }
    try {
      return normalizeLayout(window.localStorage.getItem('rf_market_layout'));
    } catch (_) {
      return 'gallery';
    }
  }

  setLayout(initialLayout(), true);
  bindControls();
  loadListings();
})();
</script>
</body>
</html>
