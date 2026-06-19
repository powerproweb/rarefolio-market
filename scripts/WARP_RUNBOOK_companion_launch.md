# WARP RUNBOOK — RareFolio companion launch (read top-to-bottom, execute in order)

**Purpose:** light up the already-sold Founders companions on the public site, then make
future sales deliver companions. Two tracks. Do **Track 1 first**.

## ⛔ CRITICAL SAFETY — read before doing anything
The Founders companions for `qd-silver-0000705..0000712` were **already delivered on-chain**
on 2026-05-19 (see `docs/FOUNDERS_COMPANION_TX_LEDGER_2026-05-19.md`). The site only shows
"not queued" because `qd_tokens.cip25_json` was never updated.
- **Do NOT run `api/private/companion-dispatch.php` in submit mode against 705..712.** Its
  idempotency guard reads `cip25_json`; since that was never updated, it would **DOUBLE-SEND**
  real companion tokens. Track 1 fixes the metadata so the guard becomes correct.
- Track 1 makes **no on-chain transactions.** It only writes DB metadata.

---

## TRACK 1 — reconcile the 8 Founders companions (metadata only, no sends)

### 1. Verify on-chain FINAL state (the ledger had mistaken sends + reclaim + burn)
Confirm each of `qd-silver-0000705..0000712` currently holds **exactly 1** of the real
companion unit:
`46cd5216baf9e1e81771731570e408fb4c392cc38db59f55ee8599a1415247454e54554d5f5052494d455f4261723031`
(ARGENTUM_PRIME_Bar01). Use a Cardano explorer on each token's current owner address, or the
sidecar after Track 2. **If any token does NOT hold it, remove that token from `$LEDGER`** in
`scripts/reconcile-founders-companion.php` before applying.

### 2. Back up qd_tokens
Dump `qd_tokens` (or at least the cip25_json of these 8 rows) with a timestamped backup.
The dry-run in step 3 also prints each current cip25_json to stdout — capture that log.

### 3. Dry run (writes nothing) and review
```
php scripts/reconcile-founders-companion.php
```
Review the OLD vs NEW cip25_json printed for each token. Confirm: companion_status=submitted,
companion_tx_hash matches the ledger, companion_unit = the ARGENTUM_PRIME unit, and the nested
`721` path got the same fields. SKIP lines = already reconciled (fine).

### 4. Apply
```
php scripts/reconcile-founders-companion.php --apply
```
Expect "Summary: 8 written" (or fewer if some were already done / removed).

### 5. Verify the public site
Load `verify.html` and `nft.html` for `qd-silver-0000705` and `qd-silver-0000712`. The
"Companion FT" / "Companion Tx" fields should now show delivered/submitted with the tx hash,
not "not queued yet". Spot-check one more (e.g. 0000709).

### 6. Commit
Commit the new `scripts/reconcile-founders-companion.php` and deploy as usual. (The script is
CLI-only guarded, so it cannot run from the web even if it lands under the docroot. Confirm
`scripts/` is 403-blocked anyway, consistent with `/src/`, `/db/`, `/sidecar/`.)

---

## TRACK 2 — make FUTURE sales deliver companions

### 7. Rebuild + restart the sidecar (fixes the /companion 404)
The companion routes exist in source but the running process is an old build.
```
cd <sidecar dir>           # report its absolute path
git pull
npm install
npm run build              # must produce dist/routes/companion.js
# restart via whatever manages it:
#   pm2:      pm2 restart <name> && pm2 logs <name> --lines 50
#   systemd:  sudo systemctl restart <svc> && systemctl status <svc>
```
Verify (internal call):
```
curl -s http://127.0.0.1:4000/health
curl -s http://127.0.0.1:4000/companion/treasury/FOUNDERS_V2/balance
```
Expect both 200 JSON (the companion call returns treasury_addr + balance), NOT 404.

### 8. Custody-model fix (separate task — do not improvise)
As wired, `buy-order.php` mints the NFT straight to the buyer, but `companion-dispatch.php`
only does PAIRED transfers (expects the NFT in the treasury) → companions won't deliver on
normal future sales. The recommended fix is **Option A** (unpaired FT-only delivery for
external-custody tokens) in `_claude_home/COMPANION_CUSTODY_DECISION.md`. **Claude will draft
the patch for review before it's applied.** Do not change companion-dispatch.php ad hoc.

---

## DO NOT
- Do NOT run companion-dispatch.php (submit mode) against 705..712.
- Do NOT re-send companions for any token that already holds one.
- Do NOT change companion-dispatch.php or buy-order.php without the reviewed Option A patch.
- Do NOT enable `COMPANION_UNPAIRED_TRANSFER_ENABLED=true` until Option A is in place (it is
  not needed for Track 1 at all).

## REPORT BACK
- Track 1: on-chain check result per token; backup location; dry-run log; apply summary;
  before/after of the verify/nft pages.
- Track 2: sidecar path + process manager + service name; the two curl outputs.
