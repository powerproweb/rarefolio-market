# WARP RUNBOOK — RareFolio small cleanups (run AFTER the Founders policy fix)

Prereq: complete `_claude_home/FOUNDERS_POLICY_DRIFT_FIX.md` first (migration 024 applied,
Bar I audit returning DRIFT=0). Then do these four. Each is independent; report results.

---

## 1. Confirm + set custody_status for the 8 Founders
On-chain, all 8 (705–712) sit in the platform custody wallet `addr1qxmq…y6kcyx` (unsold, qty 1
each). custody_status should reflect that.

**Report first:**
```sql
SELECT rarefolio_token_id, custody_status, primary_sale_status, listing_status, current_owner_wallet
FROM qd_tokens
WHERE rarefolio_token_id IN ('qd-silver-0000705','qd-silver-0000706','qd-silver-0000707',
 'qd-silver-0000708','qd-silver-0000709','qd-silver-0000710','qd-silver-0000711','qd-silver-0000712')
ORDER BY rarefolio_token_id;
```
**If they are confirmed staged-in-custody (not sold to a real collector), set:**
```sql
UPDATE qd_tokens
SET custody_status = 'platform', updated_at = NOW()
WHERE rarefolio_token_id IN ('qd-silver-0000705','qd-silver-0000706','qd-silver-0000707',
 'qd-silver-0000708','qd-silver-0000709','qd-silver-0000710','qd-silver-0000711','qd-silver-0000712')
  AND current_owner_wallet = 'addr1qxmq2vw2gmgd6hx360aus8zs9clgv06rvjsufqspupt8j2kha6v8v2623gm620zrd7gpwum4qn423y6x4dwxzvpwvfzqy6kcyx'
  AND custody_status <> 'platform';
```
**Do NOT touch `primary_sale_status` here.** If any of the 8 show `sold`/`sold_pre_marketplace`
while actually unsold-in-custody, STOP and report — that's a separate decision (it affects whether
they're sellable), not a blind flip.

## 2. Leftover E2E test token
`qd-e2e-260520231451` is a test row polluting the minted/sold set.
- The launch audit now **excludes** `qd-e2e-%` / `qd-test-%`, so it no longer affects DRIFT/WARN.
- Optional cleanup (only if you want it gone from prod): back up first, check for references in
  `qd_orders` / `qd_listings` / `qd_mint_queue`, then delete. Example check:
  ```sql
  SELECT 'orders' AS t, COUNT(*) FROM qd_orders   WHERE rarefolio_token_id='qd-e2e-260520231451'
  UNION ALL SELECT 'listings', COUNT(*) FROM qd_listings WHERE rarefolio_token_id='qd-e2e-260520231451'
  UNION ALL SELECT 'queue',    COUNT(*) FROM qd_mint_queue WHERE rarefolio_token_id='qd-e2e-260520231451';
  ```
  If all zero, deleting the single qd_tokens row is safe. If not, report before deleting.

## 3. Block web access to /scripts/
A `scripts/.htaccess` (Require all denied) is now in the repo. Deploy it, then verify the ops
scripts are not web-reachable:
```
curl -s -o /dev/null -w "%{http_code}\n" https://market.rarefolio.io/scripts/audit-silverbar01-launch-readiness.php
```
Expect **403** (and the file is CLI-guarded regardless). Confirm `/scripts/` behaves like
`/src/`, `/db/`, `/sidecar/`.

## 4. Clean the server git working tree (it blocked `git pull`)
The sidecar deploy reported untracked-file conflicts blocking `git pull`. Resolve safely —
**do not** `git clean -fdx` blindly (could wipe server-only files).
```
cd /home/rarefolio/public_html/market.rarefolio.io
git status --porcelain          # list tracked-modified + untracked
```
- Build artifacts (`sidecar/dist/`, `sidecar/node_modules/`, `vendor/`) should be gitignored —
  confirm they are; if any are untracked-and-conflicting, they can be moved aside.
- Back up any untracked file that looks like real content/config before touching it.
- Then: `git stash -u` (stashes tracked changes + untracked), `git pull`, and reconcile. Or move
  the specific conflicting untracked files aside, pull, and merge back.
- Confirm `git pull` succeeds cleanly afterward. Report `git status` before and after.

---

## REPORT BACK
- #1: the SELECT output + whether custody_status was set; flag any `sold` status surprises.
- #2: reference-check counts; whether the test row was deleted.
- #3: the curl status code for /scripts/.
- #4: `git status` before/after and confirmation that `git pull` now works.
