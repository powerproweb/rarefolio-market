# Rarefolio Configuration Guide
This document is the single source of truth for configuring the two Rarefolio
codebases so they talk to each other correctly:
- `01_rarefolio.io` — the public static site (with a small PHP `api/` surface)
- `01a_rarefolio_marketplace` — the PHP + Node marketplace
Work through the sections in order. None of it is optional for production;
a few steps can be skipped for local development and are marked as such.
## 1. The two env surfaces at a glance
| Value                                   | Lives in                                      | Type   | Must match? |
| --------------------------------------- | --------------------------------------------- | ------ | ----------- |
| `APP_ENV`, `APP_DEBUG`, `APP_URL`       | marketplace `.env`                            | plain  | no          |
| `DB_*`                                  | marketplace `.env`                            | secret | no          |
| `BLOCKFROST_*`                          | marketplace `.env`                            | secret | no          |
| `SIDECAR_BASE_URL`                      | marketplace `.env`                            | plain  | no          |
| `ADMIN_USER`, `ADMIN_PASS`              | marketplace `.env`                            | secret | no          |
| `CORS_ALLOWED_ORIGINS`                  | marketplace `.env`                            | plain  | no          |
| `RATE_LIMIT_*`, `TRUSTED_PROXY_HEADER`  | marketplace `.env`                            | plain  | no          |
| `PUBLIC_SITE_WEBHOOK_URL_BASE`          | marketplace `.env`                            | plain  | no          |
| `PUBLIC_SITE_WEBHOOK_SECRET`            | marketplace `.env`                            | secret | **yes (A)** |
| `RF_WEBHOOK_SECRET`                     | main site environment (`api/webhook/.env` or server env) | secret | **yes (A)** |
| `RF_WEBHOOK_MAX_SKEW`                   | main site environment                         | plain  | no          |
Pairs marked with the same letter must be byte-identical.
## 2. Generate the shared webhook secret
The marketplace ships with a tiny generator so you never have to leave PHP:
```powershell
php scripts/gen-webhook-secret.php
```
It prints a 64-character hex string. Paste that same string into BOTH:
- marketplace `.env` → `PUBLIC_SITE_WEBHOOK_SECRET`
- main site environment → `RF_WEBHOOK_SECRET`
> If you already generated a 32-byte hash on your own, just use that. The
> format does not matter as long as both sides have the same string.
## 3. Configure the marketplace
```powershell
Copy-Item .env.example .env
notepad .env     # or your editor of choice
```
Fill in, in order:
1. **DB_\***: real MySQL credentials (see marketplace README for schema migrations).
2. **BLOCKFROST_API_KEY**: from https://blockfrost.io (preprod for staging, mainnet for prod).
3. **ADMIN_USER / ADMIN_PASS**: admin login for `admin/*` pages.
4. **CORS_ALLOWED_ORIGINS**: comma-separated exact origins that may call the v1 API.
   - Production: `https://rarefolio.io,https://www.rarefolio.io`
   - Local dev : `http://localhost:8080`
   - Mixed     : `http://localhost:8080,https://rarefolio.io,https://www.rarefolio.io`
5. **PUBLIC_SITE_WEBHOOK_URL_BASE**: the main site's webhook base URL.
   - Production: `https://rarefolio.io/api/webhook`
   - Local dev : `http://localhost:8080/api/webhook`
6. **PUBLIC_SITE_WEBHOOK_SECRET**: paste the secret from step 2.
## 4. Configure the main site
The main site is mostly static, but `api/webhook/*.php` needs one env var:
`RF_WEBHOOK_SECRET`. Pick the method that matches your hosting:
### Shared hosting (cPanel / Plesk)
Use the control panel's "Environment Variables" or "PHP Variables" section:
```
RF_WEBHOOK_SECRET = <paste the same secret from step 2>
```
### Apache + mod_env
Add to `api/webhook/.htaccess` (alongside the existing headers):
```apache path=null start=null
SetEnv RF_WEBHOOK_SECRET <paste the same secret from step 2>
# optional:
# SetEnv RF_WEBHOOK_MAX_SKEW 300
# SetEnv RF_WEBHOOK_NONCE_DIR /var/lib/rarefolio/webhook-nonces
```
> Do NOT commit this `SetEnv` line with the real value. Either keep
> `api/webhook/.htaccess` out of git, or template the value at deploy time.
### nginx + php-fpm
In the fpm pool config (e.g. `/etc/php/8.1/fpm/pool.d/www.conf`):
```
env[RF_WEBHOOK_SECRET] = <paste the same secret from step 2>
```
Then reload fpm: `sudo systemctl reload php8.1-fpm`.
### Local dev (PowerShell, session-scoped)
```powershell
$env:RF_WEBHOOK_SECRET = "paste-the-secret-here"
php -S localhost:8080 -t M:\01_Warp_Projects\01_projects\01_rarefolio.io
```
## 5. Wire the browser client base URL
The main site's browser client in `assets/js/rf-market.js` reads
`window.RF_MARKET_BASE`. It is currently set at the top of `verify.html` and
`nft.html` to:
```html path=null start=null
<script>window.RF_MARKET_BASE = 'https://market.rarefolio.io';</script>
```
Change this one line in both pages when:
- You choose a different subdomain or subpath for the marketplace.
- You want to point local dev at `http://localhost:8080` temporarily.
## 6. Local development — minimal viable bridge
Without DNS, TLS, or a reverse proxy, you can still exercise the full bridge:
```powershell
# Terminal 1: marketplace + API on port 8081
php -S localhost:8081 -t M:\01_Warp_Projects\01_projects\01a_rarefolio_marketplace `
    M:\01_Warp_Projects\01_projects\01a_rarefolio_marketplace\tests\cli_router.php
# Terminal 2: main site on port 8080
$env:RF_WEBHOOK_SECRET = "paste-the-secret-here"
php -S localhost:8080 -t M:\01_Warp_Projects\01_projects\01_rarefolio.io
```
Then in `verify.html` / `nft.html`, temporarily change:
```html path=null start=null
<script>window.RF_MARKET_BASE = 'http://localhost:8081';</script>
```
Marketplace `.env` must include `CORS_ALLOWED_ORIGINS=http://localhost:8080`.
## 7. Production checklist
Before pointing real DNS at the marketplace, confirm all of these:
- [ ] `APP_ENV=production`, `APP_DEBUG=false` in marketplace `.env`
- [ ] `CORS_ALLOWED_ORIGINS` contains ONLY the real public origins
- [ ] `PUBLIC_SITE_WEBHOOK_SECRET` is a fresh 64-char hex (not the one from `.env.example`)
- [ ] `RF_WEBHOOK_SECRET` on the main site matches byte-for-byte
- [ ] `RATE_LIMIT_CAPACITY` + `RATE_LIMIT_WINDOW_SECONDS` are non-zero
- [ ] If behind a CDN, `TRUSTED_PROXY_HEADER` is set
- [ ] TLS cert covers whatever host serves the marketplace (subdomain or subpath)
- [ ] `window.RF_MARKET_BASE` in `verify.html` + `nft.html` points at the right host
- [ ] `php tests/test_webhook_signer.php` passes on the deployed marketplace
- [ ] `php tests/test_api_router.php` passes on the deployed marketplace
- [ ] `GET https://<marketplace-host>/api/v1/health` returns `{"ok":true}`
- [ ] `uploads/webhook-log/` exists and is writable by the web user on the main site
## 8. Secret rotation runbook
1. Generate a new secret: `php scripts/gen-webhook-secret.php`.
2. Set it first on the **main site** as `RF_WEBHOOK_SECRET`.
3. Wait for a short overlap (no outgoing webhooks will succeed during this window).
4. Update `PUBLIC_SITE_WEBHOOK_SECRET` on the **marketplace** to the new value.
5. Verify a mint-complete webhook reaches the main site (tail `uploads/webhook-log/mint-complete.log`).
6. Delete any `.bak` copies of the old secret from the filesystem.
A future enhancement (noted in `docs/WEBHOOKS.md`) will allow the receiver to
temporarily accept both old + new secrets for zero-downtime rotation.
## 9. References
- `docs/API.md` — v1 endpoints, response envelope, error codes
- `docs/WEBHOOKS.md` — signature format, events, sample payloads
- `.env.example` (marketplace) — every env var, grouped and commented
- `api/webhook/.env.example` (main site) — main-site webhook env
- `scripts/gen-webhook-secret.php` — one-step secret generator
