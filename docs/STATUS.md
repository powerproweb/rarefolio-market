# RareFolio Marketplace — Project Status
**Last updated:** 2026-04-26
**Branch:** `main` (tracking `origin/main`)
**Head commit:** `f83a63b`

---

## Current execution state

- **Phase E.2 complete (preprod minting):** all 8 Founders tokens minted and confirmed on preprod (`docs/FOUNDERS_MINT_LOG.md`)
- **Phase E.3 complete (CID replacement):** Founders IPFS CID applied via migrations:
  - `db/migrations/017_update_founders_ipfs_cids.sql`
  - `db/migrations/018_fix_founders_ipfs_cids.sql`
- **Current focus:** Phase F mainnet readiness (operational cutover + irreversible policy decisions)
- **Admin diagnostics verification complete (2026-04-26):** live authenticated dashboard check passed and rendered the Network consistency diagnostics section.
- **Diagnostics finding (resolved 2026-04-26):** app and sidecar envs are aligned to `BLOCKFROST_NETWORK=mainnet`; server-side key checks return `MAINNET_HTTP:200` and `PREPROD_HTTP:403` for both env files.
- **Task status:** Network/token drift remediation is complete; current focus is remaining Phase F hardening items.

## Local repository state

- Working tree is clean on `main` (no uncommitted changes)
- `main` is synchronized with `origin/main`

---

## Current blockers (Phase F)

1. Repeat Phase D for mainnet completion (derive/record policy ID and fund wallet)
2. Rotate webhook secret and `ADMIN_PASS`
3. Remove `verify.php` / `tests` from production web root and block `src/`, `db/`, `sidecar/` from HTTP access
4. Complete production checklist + final smoke checks before Phase G launch

---

## Next execution sequence

1. Complete `docs/LAUNCH_CHECKLIST.md` Phase F items in order.
2. Confirm mainnet sidecar health and policy readiness.
3. Run smoke checks (`sidecar/test-smoke.mjs`, `api/v1/health`, admin login).
4. Proceed to launch-day steps in Phase G only after Phase F is clean.

---

## What is shipped (code/platform)

- Phase 1 scaffold and admin foundation
- Phase 1.5 public API + signed webhook bridge
- Phase 2 sidecar minting, ownership sync, and listings schema/API
- Preprod mint execution path validated end-to-end through 8/8 Founders

## Post-launch roadmap (unchanged)

- Phase 3: secondary listings UX, offers/auctions, realtime notifications
- Phase 4: editorial/CMS, rarity/traits, watchlists + collector social
- Phase 5: fiat rails, multi-chain expansion, CIP-68 richer metadata

## Known technical debt

- `qd_tokens.current_owner_user_id` FK to `qd_users` is not yet enforced (column exists, FK commented out pending user table migration run)
- `royalty_ledger.listing_id` and `royalty_ledger.order_id` FKs are placeholders until `qd_listings` and `qd_orders` are populated
- Admin auth (`ADMIN_USER` / `ADMIN_PASS`) is a single shared credential; should be replaced with per-user auth once `qd_users` is populated
- `api/v1/routes/bars_show.php` is a stub; needs real silver bar aggregation logic once tokens are minted
