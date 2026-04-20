# RareFolio.io — Launch Checklist

**Code baseline:** `226f175` (main)
**Status:** Code complete. All remaining items are creative, operational, or chain work.

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
- [ ] Update `db/migrations/007_seed_founders_block88_tokens.sql`
  — replace every `ipfs://REPLACE_WITH_CID/qd-silver-XXXXXXX.jpg`
    with the real `ipfs://Qm...` URI for each token
- [ ] Verify the updated descriptions and character names look correct in the file
- [ ] See `docs/MEDIA.md` for the full pinning workflow

---

## PHASE C — Server Setup (preprod first, then mainnet)

- [ ] SSH into the server; confirm PHP 8.1+ and Node 20+ are available
- [ ] Upload / pull latest code from `main` (`742f5ae`)
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

## PHASE E — Mint the Founders Collection (preprod first)

Do one token first, verify fully, then continue.

- [ ] Log into `https://rarefolio.io/admin/login.php`
- [ ] Open **Mint queue → Founders #1 (qd-silver-0000705)**
- [ ] Confirm on-chain identifiers are correct: policy_id, asset_name_hex, image CID
- [ ] Click **"Build & sign tx (sidecar)"** — review the JSON response
- [ ] Click **"Submit to chain"** — record the tx_hash
- [ ] Click **"Check confirmation"** every ~30 seconds until confirmed
- [ ] Verify on the public API: `GET /api/v1/tokens/qd-silver-0000705`
  — confirm `primary_sale`: `"minted"`, `mint_tx_hash` is set
- [ ] Check provenance: `admin/activity.php` should show a `mint` event
- [ ] Verify webhook fired to main site: check `uploads/webhook-log/mint-complete.log` on the main site
- [ ] Repeat for Founders #2–#8 (`qd-silver-0000706` through `qd-silver-0000712`)

---

## PHASE F — Pre-launch Hardening

- [ ] Switch to mainnet: update `BLOCKFROST_NETWORK=mainnet` and `BLOCKFROST_API_KEY` (mainnet key) in both `.env` files
- [ ] Generate a fresh mainnet `POLICY_MNEMONIC` (never reuse preprod keys)
- [ ] Repeat Phase D for mainnet (derive policy ID, fund wallet)
- [ ] Confirm `APP_ENV=production` and `APP_DEBUG=false`
- [ ] Confirm `CORS_ALLOWED_ORIGINS` contains only `https://rarefolio.io,https://www.rarefolio.io`
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
