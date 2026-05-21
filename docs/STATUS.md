# RareFolio Marketplace - Project Status
**Last updated:** 2026-05-20
**Branch:** `restore/stash-pre-branch-cleanup-20260520`
---
## Current execution state
- **Phase E.2 complete (preprod minting):** all 8 Founders tokens minted and confirmed on preprod (`docs/FOUNDERS_MINT_LOG.md`)
- **Phase E.3 complete (CID replacement):** Founders IPFS CID applied via:
  - `db/migrations/017_update_founders_ipfs_cids.sql`
  - `db/migrations/018_fix_founders_ipfs_cids.sql`
- **Claim verifier auth mismatch hardening complete (2026-05-20):**
  - `api/private/ownership-verify.php` now authenticates against a bounded candidate set:
    - `DOWNLOAD_VERIFY_SHARED_SECRET`
    - `DOWNLOAD_VERIFY_SHARED_SECRET_PREVIOUS` (optional)
    - `PUBLIC_SITE_WEBHOOK_SECRET` fallback when `DOWNLOAD_VERIFY_ALLOW_WEBHOOK_FALLBACK=true`
  - ownership verifier now also accepts `X-Download-Verify-Secret` in addition to existing auth headers.
  - env template updated in `.env.example` for rotation-safe verifier auth settings.
- **Claim verifier production closure evidence captured (2026-05-20):**
  - final live smoke for `QDCERT-E101837-0000705` / `qd-silver-0000705` completed with real wallet signature:
    - challenge `POST https://rarefolio.io/api/download/challenge.php` -> `200`
    - claim `POST https://rarefolio.io/api/download/claim.php` -> `200`
    - claim response returned ticketed `download_url`
  - ownership verifier log audit (`/etc/apache2/logs/domlogs/rarefolio/market.rarefolio.io-ssl_log`) confirms:
    - historical mismatch-era `401` at `18:35:07 -0400`
    - post-fix calls at `18:38:04 -0400` and `18:51:06 -0400` returned `200`
    - no additional post-fix `unauthorized` ownership-verify entries observed
- **Non-burn policy and CID cutover complete in production (2026-05-18):**
  - `db/migrations/020_non_burn_collection_policy_cutover.sql` applied at `2026-05-18 10:10:27`
  - `db/migrations/021_non_burn_cid_cutover.sql` applied at `2026-05-18 10:12:09`
  - `qd_collections.slug=silverbar-01-founders-v2` resolves to `policy_env_key=FOUNDERS_V2`
- **2026-05-19 migration execution + mint policy smoke closure:**
  - applied policy-key alignment updates for `silverbar-01-founders-v2`:
    - `qd_tokens` v721 policy-key rewrites: `8`
    - `qd_mint_queue` v721 policy-key rewrites: `8`
    - `policy_id` mismatch count after apply: `0` across both tables
  - added migration artifact:
    - `db/migrations/022_align_founders_v2_policy_v721_keys.sql`
  - final non-destructive mint smoke test passed:
    - `GET /mint/policy-id?env_key=FOUNDERS_V2` -> `82ae9440500e297e49144a13832861de3e84e526eee0eb70f4d48af7`
    - `GET /mint/policy-id?env_key=FOUNDERS` -> `e29ba98c7633bb62e8d8d0f5013a4a2dee3d1872e00032a0f524c9e9`
    - build-only `POST /mint/prepare` passed for both env keys with distinct policy IDs
- **Mint prepare policy-key resolution fix applied (2026-05-20):**
  - `admin/mint-action.php` no longer forwards `qd_mint_queue.policy_id` to sidecar `/mint/prepare`
  - policy script resolution is now forced through `qd_collections.policy_env_key` to prevent stale pre-cutover policy_id override
- **Migration plan verification rerun complete (2026-05-20):**
  - production `php db/migrate.php --mode=plan` reports `Pending 0 migration(s)` including `022_align_founders_v2_policy_v721_keys.sql`
- **Founders V2 sidecar policy readiness remains healthy:**
  - `GET /mint/policy-id?env_key=FOUNDERS_V2` returns `200`
  - policy id: `82ae9440500e297e49144a13832861de3e84e526eee0eb70f4d48af7`
- **Mainnet mint verification remains complete:**
  - direct sidecar verification mint tx: `06161d3dbbed8539f83006d07fa7c181a7f733f7d90e9cb6ee82634b147588b2`
  - full marketplace verification mint tx: `fddf585a9424ce267deca8eac603bb696f5d5e9c5fdf9d3865aa47f2b1402bf8`
- **2026-05-19 production verification closure completed (listing_id fix):**
  - permanent fix shipped in `api/buy-order.php`:
    - resolve active `qd_listings` row under lock
    - enforce fixed-price listing for this order path
    - bind `:listing_id` into `qd_orders` insert instead of `NULL`
    - use listing asking price first, then collection fallback
  - deployed with timestamped backups:
    - `/home/rarefolio/public_html/market.rarefolio.io/api/buy-order.php.bak_20260519T133714Z`
    - `/home/rarefolio/www/market.rarefolio.io/api/buy-order.php.bak_20260519T133714Z`
  - clean production retest succeeded with no DB workaround:
    - `order_id=9`
    - `order_tx_hash=cd356a127ebe19d6de01f8f2def6eb3ec5e877dce212ff04fce7e7f198873b16`
    - `qd_orders.listing_id=28` (non-null, valid FK)
    - `mint_tx_hash=efb84df86577657fb29411d8c13c453b975c3f21f06f479e25c0e22fff52e9db`
    - Blockfrost tx lookup for mint tx returned `200`
    - `order-status.php?order=9` returned `200`
  - controlled fixture cleanup completed:
    - removed order `9`, listing `28`, token `37`
    - no residual test rows remain
  - trigger workaround remains absent:
    - `qd_orders_bi_fill_listing_id` count = `0`
- **Phase F hardening checks advanced on 2026-05-19:**
  - `APP_ENV=production`, `APP_DEBUG=false`
  - `CORS_ALLOWED_ORIGINS=https://rarefolio.io,https://www.rarefolio.io`
  - rate limit settings are non-zero
  - `TRUSTED_PROXY_HEADER=X-Forwarded-For`
  - marketplace health endpoint returns `200`
  - `verify.php` and `tests/` are absent from both market web roots
  - `/src/`, `/db/`, and `/sidecar/` return `403`
  - TLS responds successfully on `https://market.rarefolio.io/`
  - main-site `verify.html` and `nft.html` point to `https://market.rarefolio.io`
- **Phase F hardening execution completed (items 1-4):**
  - webhook secret rotated across Market sender and main-site receiver (`PUBLIC_SITE_WEBHOOK_SECRET` and `RF_WEBHOOK_SECRET`)
  - rotated webhook validated by live signed `mint.complete` test event:
    - `cnft_id=qd-whsec-rotate-1779203652`
    - `tx_hash=fc3036a374b138048963157c4d0a41db8b5b05872379758fe6528323a81d7d8d`
    - sender result `status=200`, receiver log append confirmed
  - secret parity and format verified without disclosure:
    - both secrets are 64-char hex
    - sender/receiver secret hash comparison matched
  - Market admin credential rotated and validated:
    - wrong password returns invalid credentials (HTTP `200`, no redirect)
    - new password login returns HTTP `302` redirect to `/admin/index.php`
  - `docs/CONFIG.md` section 7 evidence closed:
    - local tests: `tests/test_webhook_signer.php` passed (`6/6`), `tests/test_api_router.php` passed (`4/4`)
    - production checks reconfirmed (`/api/v1/health`, TLS, webhook log writable path)
  - `FOUNDERS_V2` split mnemonic issue resolved:
    - `SPLIT_MNEMONIC_FOUNDERS_V2` set in sidecar env
    - sidecar process recycled
    - `GET /sweep/balance/FOUNDERS_V2` now returns wallet address and balance payload
## Local repository state
- Working tree includes launch-tracking documentation refresh updates for final claim-verifier closure evidence.
---
## Current blockers (Phase G gate)
1. Execute launch announcement window.
---
## Next execution sequence
1. Publish launch announcement.
2. Start post-launch watch and operational monitoring.
---
## What is shipped (code/platform)
- Phase 1 scaffold and admin foundation
- Phase 1.5 public API and signed webhook bridge
- Phase 2 sidecar minting, ownership sync, and listings schema/API
- Preprod mint execution path validated end-to-end through 8/8 Founders
- Mainnet non-burn policy and CID cutover completed and validated
## Post-launch roadmap (unchanged)
- Phase 3: secondary listings UX, offers/auctions, realtime notifications
- Phase 4: editorial/CMS, rarity/traits, watchlists plus collector social
- Phase 5: fiat rails, multi-chain expansion, CIP-68 richer metadata
## Known technical debt
- `qd_tokens.current_owner_user_id` FK to `qd_users` is not yet enforced (column exists, FK commented out pending user table migration run)
- `royalty_ledger.listing_id` and `royalty_ledger.order_id` FKs are placeholders until `qd_listings` and `qd_orders` are populated
- Admin auth (`ADMIN_USER` and `ADMIN_PASS`) is a single shared credential and should be replaced with per-user auth once `qd_users` is populated
- `api/v1/routes/bars_show.php` is a stub and needs real silver bar aggregation logic once tokens are minted