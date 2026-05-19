# RareFolio Marketplace - Project Status
**Last updated:** 2026-05-18
**Branch:** `wip/non-burn-policy-cid-cutover-local` (tracking `origin/wip/non-burn-policy-cid-cutover-local`)
**Head commit:** `e879318`
---
## Current execution state
- **Phase E.2 complete (preprod minting):** all 8 Founders tokens minted and confirmed on preprod (`docs/FOUNDERS_MINT_LOG.md`)
- **Phase E.3 complete (CID replacement):** Founders IPFS CID applied via migrations:
  - `db/migrations/017_update_founders_ipfs_cids.sql`
  - `db/migrations/018_fix_founders_ipfs_cids.sql`
- **Non-burn policy and CID cutover complete in production (2026-05-18):**
  - `db/migrations/020_non_burn_collection_policy_cutover.sql` applied at `2026-05-18 10:10:27`
  - `db/migrations/021_non_burn_cid_cutover.sql` applied at `2026-05-18 10:12:09`
  - `qd_collections.slug = silverbar-01-founders-v2` now resolves to `policy_env_key = FOUNDERS_V2`
- **Founders V2 sidecar configuration complete:**
  - `POLICY_MNEMONIC_FOUNDERS_V2` restored in production sidecar env
  - `GET /mint/policy-id?env_key=FOUNDERS_V2` returns `200` with policy `82ae9440500e297e49144a13832861de3e84e526eee0eb70f4d48af7`
- **Mint verification complete on mainnet:**
  - Sidecar direct test mint confirmed:
    - `tx_hash = 06161d3dbbed8539f83006d07fa7c181a7f733f7d90e9cb6ee82634b147588b2`
    - `asset_fingerprint = asset1ke2txnq95qxncgcpg0e54khwfsemnnajwthd8q`
  - Full marketplace lazy-mint path confirmed via controlled fixture:
    - `order_id = 8`
    - `mint_tx_hash = fddf585a9424ce267deca8eac603bb696f5d5e9c5fdf9d3865aa47f2b1402bf8`
    - `asset_fingerprint = asset1cc08y7pqnnuc5uh44pwkv2w2x6e8n3st8lzkxy`
- **Operational cleanup complete:**
  - Temporary fixture collection, token, listing, and order rows removed
  - Temporary DB trigger workaround removed
  - Local rotated-credential temp file removed
## Local repository state
- Working tree was clean on `wip/non-burn-policy-cid-cutover-local` at time of this status update
- Branch was synchronized with `origin/wip/non-burn-policy-cid-cutover-local` before this documentation commit
---
## Current blockers (Phase F)
1. **Critical:** `api/buy-order.php` still inserts `listing_id = NULL` while `qd_orders.listing_id` is `NOT NULL`. A permanent code-level fix is required before relying on new primary sale order creation.
2. Repeat Phase D for mainnet completion (derive and record policy ID and fund wallet)
3. Rotate webhook secret and `ADMIN_PASS`
4. Remove `verify.php` and `tests` from production web root and block `src/`, `db/`, and `sidecar/` from HTTP access
5. Complete production checklist and final smoke checks before Phase G launch
---
## Next execution sequence
1. Ship the permanent `listing_id` fix in `api/buy-order.php`.
2. Complete `docs/LAUNCH_CHECKLIST.md` Phase F items in order.
3. Confirm mainnet sidecar health and policy readiness.
4. Run smoke checks (`sidecar/test-smoke.mjs`, `api/v1/health`, admin login).
5. Proceed to launch-day steps in Phase G only after Phase F is clean.
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