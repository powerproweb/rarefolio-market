# RareFolio.io — Launch Checklist

**Code baseline:** `f83a63b` (main)
**Status:** Preprod minting complete (E.2) + Founders CID replacement complete (E.3). Network/policy Phase F blockers were cleared on 2026-04-26; remaining Phase F work is production hardening.

---

## Current execution snapshot (2026-04-26)

- [x] **E.2 complete:** all 8 Founders tokens minted + confirmed on preprod (`docs/FOUNDERS_MINT_LOG.md`)
- [x] **E.3 complete:** CID replacement applied using `db/migrations/017_update_founders_ipfs_cids.sql` and `db/migrations/018_fix_founders_ipfs_cids.sql`
- [x] Enable cPanel **Normal Shell** access for sidecar CI/CD (verified via SSH)
- [x] Generate fresh mainnet `POLICY_MNEMONIC` / `POLICY_MNEMONIC_FOUNDERS` in `sidecar/.env` (24-word validation passed)
- [x] Finalize `POLICY_LOCK_SLOT` decision before first mainnet mint (current decision: no timelock for Founders; value intentionally blank)
- [x] Resolve network mismatch: app + sidecar both set `BLOCKFROST_NETWORK=mainnet`
- [x] Verify Blockfrost key/network alignment on server (`MAINNET_HTTP:200`, `PREPROD_HTTP:403` for both env files)

---

## PHASE A — Creative (your team, no code required)

- [ ] Finalize all 8 Founders Block 88 artwork files
  - The Archivist — Keeper of the First Ledger (`qd-silver-0000705`)
  - The Cartographer — Drafter of the Vault Map (`qd-silver-0000706`)
  - The Sentinel — Warden of the Inaugural Seal (`qd-silver-0000707`)
  - The Artisan — Forger of the Foundational Die (`qd-silver-0000708`)
  - The Scholar — Historian of the First Provenance (`qd-silver-0000709`)
  - The Ambassador — Emissary of the Original Charter (`qd-silver-0000710`)
  - The Mentor — Steward of the Collector's Path (`qd-silver-0000711`)
  - The Architect — Builder of the Permanent Vault (`qd-silver-0000712`)
- [ ] Write character story / description for each of the 8 Founders
- [ ] Export artwork at final resolution (≥ 2000×2000px, JPEG, sRGB, < 5 MB each)

---

## PHASE B — IPFS & Metadata

- [ ] Create accounts on Pinata (or nft.storage) if not already set up
- [ ] Pin each of the 8 artwork files to IPFS — record the CID for each
- [ ] Verify each CID resolves before proceeding:
  ```
  curl -I https://gateway.pinata.cloud/ipfs/<CID>
  # Expect: HTTP 200, Content-Type: image/jpeg
  ```
- [x] Apply Founders CID replacement migrations:
  - `db/migrations/017_update_founders_ipfs_cids.sql`
  - `db/migrations/018_fix_founders_ipfs_cids.sql`
- [ ] Verify the updated descriptions and character names look correct in the file
- [ ] See `docs/MEDIA.md` for the full pinning workflow

---

## PHASE C — Server Setup (preprod first, then mainnet)

- [ ] SSH into the server; confirm PHP 8.1+ and Node 20+ are available
- [ ] Upload / pull latest code from `main` (`f83a63b`)
- [ ] Copy and configure both env files:
  - `cp .env.example .env` → fill in `DB_*`, `BLOCKFROST_API_KEY` (preprod), `ADMIN_USER`, `ADMIN_PASS`, `CORS_ALLOWED_ORIGINS`, webhook vars
  - `cp sidecar/.env.example sidecar/.env` → fill in `BLOCKFROST_API_KEY` (preprod), `POLICY_MNEMONIC` (see Phase D), `PLATFORM_PAYOUT_ADDR`, `CREATOR_ROYALTY_ADDR`
- [ ] Create the MySQL database and user (see `docs/CONTRIBUTING.md`)
- [ ] Run migrations: `php db/migrate.php`
  — confirm output shows migrations 001–012 applied
- [ ] Install sidecar dependencies: `cd sidecar && npm ci`
- [ ] Start the sidecar (pm2 or systemd): `pm2 start "npm start" --name rarefolio-sidecar`
- [ ] Verify sidecar health: `curl http://localhost:4000/health`
  — expect `"ok":true, "policy_ready":true`
- [ ] Run PHP tests to confirm environment: `php tests/test_env_pair.php`

---

## PHASE D — Policy Wallet (Cardano)

- [ ] Generate a 24-word mnemonic for the policy wallet:
  ```bash
  cd sidecar && npm run dev
  # In another terminal:
  npx @meshsdk/core generate-mnemonic
  ```
- [ ] Add mnemonic to `sidecar/.env` as `POLICY_MNEMONIC`
- [ ] Get the policy wallet address: `curl http://localhost:4000/mint/policy-id`
  — note the `policy_id` and `policy_addr`
- [ ] Fund the policy wallet with ADA:
  - Preprod: use the Cardano faucet → `https://docs.cardano.org/cardano-testnets/tools/faucet/`
  - Mainnet: send ≥ 5 ADA from your own wallet to `policy_addr`
- [ ] Re-run `curl http://localhost:4000/mint/policy-id` and **record the policy_id permanently** (it never changes for this mnemonic + lock slot combination)
- [ ] Decide on time-lock: if you want a hard supply cap, set `POLICY_LOCK_SLOT` in `sidecar/.env` now, before the first mint (see `docs/CARDANO.md`)
- [ ] Update `qd_tokens.policy_id` for all 8 Founders rows:
  ```sql
  UPDATE qd_tokens
  SET    policy_id = '<your-policy-id>'
  WHERE  collection_slug = 'silverbar-01-founders';
  ```
- [ ] Update `qd_mint_queue.policy_id` for any queued rows:
  ```sql
  UPDATE qd_mint_queue
  SET    policy_id = '<your-policy-id>'
  WHERE  collection_slug = 'silverbar-01-founders';
  ```
- [ ] Back up the mnemonic in a password manager (treat it like a private key)
- [ ] See `docs/CARDANO.md` for full details

---

## PHASE E — Mint the Founders Collection (preprod first) — COMPLETE

Gate passed on 2026-04-24. See `docs/FOUNDERS_MINT_LOG.md` for tx hashes and verification evidence.

- [x] Log into `https://rarefolio.io/admin/login.php`
- [x] Open **Mint queue → Founders #1 (qd-silver-0000705)**
- [x] Confirm on-chain identifiers are correct: policy_id, asset_name_hex, image CID
- [x] Click **"Build & sign tx (sidecar)"** — review the JSON response
- [x] Click **"Submit to chain"** — record the tx_hash
- [x] Click **"Check confirmation"** every ~30 seconds until confirmed
- [x] Verify on the public API: `GET /api/v1/tokens/qd-silver-0000705`
  — confirm `primary_sale`: `"minted"`, `mint_tx_hash` is set
- [x] Check provenance: `admin/activity.php` should show a `mint` event
- [x] Verify webhook fired to main site: check `uploads/webhook-log/mint-complete.log` on the main site
- [x] Repeat for Founders #2–#8 (`qd-silver-0000706` through `qd-silver-0000712`)

---

## PHASE F — Pre-launch Hardening
- [x] Enable cPanel shell access (`SSH Access` → `Manage Shell Access` → `Normal Shell`)

- [x] Switch to mainnet: update `BLOCKFROST_NETWORK=mainnet` and `BLOCKFROST_API_KEY` (mainnet key) in both `.env` files
- [x] Generate a fresh mainnet `POLICY_MNEMONIC` (never reuse preprod keys)
- [x] Finalize `POLICY_LOCK_SLOT` decision (no timelock for current Founders mainnet run; blank in `sidecar/.env` by design)
- [ ] Repeat Phase D for mainnet (derive policy ID, fund wallet)
- [x] Confirm `APP_ENV=production` and `APP_DEBUG=false`
- [x] Confirm `CORS_ALLOWED_ORIGINS` contains only `https://rarefolio.io,https://www.rarefolio.io`
- [ ] Rotate webhook secret: `php scripts/gen-webhook-secret.php` → update both sides
- [ ] Generate a fresh `ADMIN_PASS` and update `.env`
- [ ] Remove `verify.php` and `tests/` from production web root
- [ ] Block `src/`, `db/`, `sidecar/` from HTTP access (`.htaccess` or nginx config)
- [ ] Run production checklist: `docs/CONFIG.md` § 7
- [ ] TLS cert active for the marketplace subdomain
- [ ] Set `window.RF_MARKET_BASE` to the real marketplace URL in `verify.html` + `nft.html` on the main site

---

## PHASE G — Launch Day

- [ ] Point DNS to the marketplace server
- [ ] DNS propagation check (`dig +short rarefolio.io`)
- [ ] Final smoke test: `node sidecar/test-smoke.mjs`
- [ ] Final API test: `curl https://rarefolio.io/api/v1/health`
- [ ] Final admin login check: `https://rarefolio.io/admin/`
- [ ] Announce

---

## Phases 3–5 (post-launch, not blocking)

- Secondary listings UI + offer system
- Auction engine + anti-sniping
- Real-time notifications
- CIP-27 royalty token on-chain
- Editorial / CMS layer
- Fiat rails + multi-chain expansion
