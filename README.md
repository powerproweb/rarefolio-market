# RareFolio.io Marketplace

A Cardano-first curated NFT marketplace. PHP + MySQL backend, Node.js/TypeScript
Cardano sidecar (Mesh SDK), read-only public API, and signed webhook bridge to
the main `rarefolio.io` site.

## Quick start

```powershell
# 1. Configure
Copy-Item .env.example .env            # edit with DB creds + BLOCKFROST_API_KEY
Copy-Item sidecar\.env.example sidecar\.env  # edit with BLOCKFROST_API_KEY + POLICY_MNEMONIC

# 2. Create DB + run all migrations
php db/migrate.php

# 3. Start the sidecar (separate terminal)
cd sidecar && npm install && npm run dev

# 4. Start the PHP dev server
php -S localhost:8080 -t . tests/cli_router.php
# then visit http://localhost:8080/admin/login.php

# 5. Run repository verification
php verify.php
# checks syntax/tests plus migration SQL sanity for both DDL and DML files
```
## E2E test harness

The lazy-mint E2E smoke harness lives at `tests/test_lazy_mint_e2e.php`.

Run against local dev server mode:

```powershell
php tests/test_lazy_mint_e2e.php
```

Run against deployed environment mode:

```powershell
cmd /c "set E2E_BASE_URL=https://market.rarefolio.io&& set E2E_INSECURE_TLS=1&& php tests/test_lazy_mint_e2e.php"
```

Behavior notes:
- The harness no longer requires `mbstring`; it falls back safely when `mb_substr` is unavailable.
- In local mode, DB-dependent assertions are skipped when `/api/v1/health` reports `data.db != ok`.
- In external mode (`E2E_BASE_URL` set), all assertions remain strict and no DB-readiness skips are applied.

## Documentation

| Document | Purpose |
|---|---|
| `docs/STATUS.md` | **Start here** — what is shipped, blockers, next steps |
| `docs/ARCHITECTURE.md` | System diagram + component responsibilities |
| `docs/CARDANO.md` | Policy setup, mint flow, ownership sync, preprod → mainnet |
| `docs/MEDIA.md` | Artwork pinning, IPFS CID workflow, Founders seed update |
| `docs/CONTRIBUTING.md` | Local setup, migrations, tests, sidecar dev, conventions |
| `docs/API.md` | Public API v1 endpoints + error envelope |
| `docs/WEBHOOKS.md` | Signed outbound webhook format + events |
| `docs/CONFIG.md` | End-to-end config walkthrough (marketplace ↔ main site) |
| `rarefolio_marketplace_php_site_plan.md` | Full product blueprint (Unified Blueprint v2) |

## Stack

- **PHP 8.1+** — admin dashboard, public API, webhook bridge
- **MySQL 8 / MariaDB 10.6+** — primary database (12 migrations)
- **Node.js 20+ / TypeScript** — Cardano sidecar (Mesh SDK, Blockfrost)
- **No Composer dependencies** — pure PHP, no vendor directory
