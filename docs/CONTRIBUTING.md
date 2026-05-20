# Contributing to RareFolio Marketplace

## Prerequisites

- PHP 8.1+ with extensions: `pdo_mysql`, `curl`, `mbstring`, `json`
- MySQL 8 or MariaDB 10.6+
- Node.js 20+
- A Blockfrost preprod project API key (https://blockfrost.io)

## Local setup

### 1. Clone and configure

```powershell
# Copy and edit environment files
Copy-Item .env.example .env
Copy-Item sidecar\.env.example sidecar\.env
# Edit both files with your DB credentials, Blockfrost key, etc.
```

### 2. Create the database

```sql
CREATE DATABASE rarefolio CHARACTER SET utf8mb4;
CREATE USER 'rarefolio'@'localhost' IDENTIFIED BY 'your-password';
GRANT ALL ON rarefolio.* TO 'rarefolio'@'localhost';
FLUSH PRIVILEGES;
```

### 3. Run migrations

```powershell
php db/migrate.php
```

Migrations are applied in lexical order (001 → 012). Already-applied
migrations are skipped. The migration state is stored in `schema_migrations`.
Additional safety modes:

```powershell
php db/migrate.php --mode=plan
php db/migrate.php --mode=dry-run
php db/migrate.php --mode=dry-run --dry-run-db=rarefolio_market_dryrun
```

- `plan` lists pending migrations and validates guard rules without applying SQL.
- `dry-run` clones the current schema into a shadow database and applies pending
  migrations there.
  - Default behavior uses an ephemeral shadow DB (requires `CREATE DATABASE` privilege).
  - For shared hosting production, provide a pre-created shadow DB with either:
    - `--dry-run-db=<name>` (CLI), or
    - `MIGRATION_DRY_RUN_DB=<name>` in `.env`.
  - When using `MIGRATION_DRY_RUN_DB`, the runner resets that schema in place before each dry-run.

### 4. Start the sidecar

```powershell
cd sidecar
npm install          # or: npm ci
npm run dev          # tsx watch (hot reload)
# production: npm run build && npm start
```

The sidecar listens on `http://localhost:4000` by default.

### 5. Start the PHP dev server

```powershell
# From the repo root (separate terminal)
php -S localhost:8080 -t . tests/cli_router.php
```

Browse to `http://localhost:8080/admin/login.php`.

---

## Running tests

```powershell
# All PHP tests (no framework — each file is standalone)
php tests/test_cip25_validator.php
php tests/test_webhook_signer.php
php tests/test_founders_seed_static.php
php tests/test_env_pair.php
php tests/test_api_router.php
php verify.php

# TypeScript typecheck
cd sidecar
npm run typecheck
```

No test runner is configured yet — each test file exits 0 on pass, 1 on fail
and prints pass/fail lines to stdout.

`verify.php` also validates migration files and accepts executable schema and
data migrations (DDL or DML), not only `CREATE TABLE` statements.

---

## Sidecar development

The sidecar uses `tsx` for hot-reload development:

```powershell
cd sidecar
npm run dev         # starts with tsx watch
npm run typecheck   # tsc --noEmit (no compilation output)
npm run build       # tsc → dist/
npm start           # node dist/index.js (production)
```

Key source files:
- `src/index.ts` — express app setup, route mounts
- `src/lib/blockfrost.ts` — Blockfrost singleton
- `src/lib/policy.ts` — native script + policy ID derivation
- `src/routes/mint.ts` — `/mint/prepare`, `/mint/submit`, `/mint/policy-id`
- `src/routes/sync.ts` — `/sync/token/:unit`, `/sync/policy/:policyId`
- `src/routes/asset.ts` — `/asset/:unit`, `/policy/:policyId/assets`
- `src/routes/handle.ts` — `/handle/:handle`

---

## Database migrations

Migration files live in `db/migrations/*.sql` and are numbered sequentially.
The migration runner (`db/migrate.php`) applies them in order and records each
applied file in the `schema_migrations` table.

Auto-run migration safety contract:
- Auto-run migrations must not use dynamic SQL control statements (`PREPARE`, `EXECUTE`, `DEALLOCATE PREPARE`, `SIGNAL SQLSTATE`)
- Auto-run migrations must not ship unresolved `REPLACE_*` placeholders
- One-off operational migrations must include `-- @ops_only` at the top and are skipped by `db/migrate.php`

To add a new migration:
1. Create `db/migrations/013_your_description.sql`
2. Write idempotent SQL (use `CREATE TABLE IF NOT EXISTS`, `ON DUPLICATE KEY UPDATE`, etc.)
3. Run `php db/migrate.php`

Never edit already-applied migrations in production. Create a new migration file instead.

---

## Code conventions

### PHP
- `declare(strict_types=1)` at the top of every file
- Namespaced under `RareFolio\`
- No external composer dependencies (intentional)
- PDO with prepared statements everywhere — no raw string interpolation in queries
- Output escaping via the `h()` helper in admin pages

### TypeScript (sidecar)
- Strict mode (`"strict": true` in tsconfig.json)
- ESM modules only (`"type": "module"`)
- Zod for all input validation at route boundaries
- Async/await throughout; `next(err)` for error propagation to the express error handler

### Git
- Branch from `main`; squash-merge to `main`
- Commit messages: imperative mood, present tense
  - Good: `Add ownership sync routes to sidecar`
  - Bad: `Added ownership sync routes to sidecar`
- Include co-author line for AI-assisted commits:
  ```
  Co-Authored-By: Oz <oz-agent@warp.dev>
  ```

### Release contract
- Canonical production branch: `main`
- Deploy workflow (`.github/workflows/deploy.yml`) is valid only for ref `refs/heads/main`
- `workflow_dispatch` runs must target `main`; non-main refs are blocked by workflow guard
- `production` is not an auto-deploy source branch; treat it as an optional coordination branch only
- Rollback path: revert/cherry-pick fixes into `main`, then redeploy from `main`

---

## Deployment

See `dist/DEPLOY.md` for the FTP deploy runbook and `docs/CONFIG.md` for
the full production configuration walkthrough.

Production deploy trigger policy:
- Automatic: push to `main`
- Manual: run `Deploy marketplace` on `main` only
- Any attempt to run this workflow from other refs fails fast by design

Before deploying:
- `APP_ENV=production`, `APP_DEBUG=false`
- `CORS_ALLOWED_ORIGINS` contains only real production origins
- `PUBLIC_SITE_WEBHOOK_SECRET` is a fresh 64-char hex secret
- `MIGRATION_DRY_RUN_DB` exists on the server and is writable by `DB_USER`
- GitHub Actions secret `MIGRATION_DRY_RUN_DB` matches server `.env` (for deploy dry-run)
- `RATE_LIMIT_CAPACITY` and `RATE_LIMIT_WINDOW_SECONDS` are non-zero
- `POLICY_MNEMONIC` is set and the policy wallet is funded
- `verify.php` and `tests/` are removed from the production web root
