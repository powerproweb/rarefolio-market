# RareFolio.io Marketplace — Phase 1 Scaffold

Phase 1 deliverables per the Unified Blueprint v2:

1. `qd_tokens` + `qd_mint_queue` tables (SQL migrations under `db/migrations/`)
2. Admin mint dashboard at `admin/mint.php`
3. CIP-25 metadata builder form + validator at `admin/mint-new.php` + `src/Cip25/Validator.php`
4. Blockfrost API PHP client at `src/Blockfrost/Client.php`
5. Node.js/TypeScript sidecar skeleton under `sidecar/`

Plus the Pre-Marketplace Mint Checklist backbone:

- `qd_presales` table + `qd_presales_template.csv` seed template
- `gifts`, `ada_handles`, `royalty_ledger` tables

## Prerequisites

- PHP 8.1+ with `pdo_mysql`, `curl`, `mbstring`, `json`
- MySQL 8 or MariaDB 10.6+
- Node.js 20+ (for the sidecar)
- Composer (optional — current code uses no vendor dependencies)
- A Blockfrost project API key (preprod for testing, mainnet for production)

## Setup

```powershell
# 1. Copy env template
Copy-Item .env.example .env
# edit .env with DB creds + BLOCKFROST_API_KEY

# 2. Create DB and run migrations
# (create the database manually first, e.g. CREATE DATABASE rarefolio;)
php db/migrate.php

# 3. Start the sidecar (separate terminal)
cd sidecar
Copy-Item .env.example .env
# edit sidecar/.env with BLOCKFROST_API_KEY
npm install
npm run dev

# 4. Open the admin dashboard in a browser
# (serve the project root via your local PHP / Apache / Nginx)
php -S localhost:8080
# then visit http://localhost:8080/admin/mint.php
```

## Directory layout

```
01a_rarefolio_marketplace/
├── admin/                     # Admin PHP pages
│   ├── mint.php               # Mint queue dashboard
│   ├── mint-new.php           # CIP-25 metadata builder form
│   ├── mint-validate.php      # AJAX validator endpoint
│   └── includes/              # Shared header/footer/auth
├── assets/
│   └── admin.css              # Dashboard styling
├── db/
│   ├── migrate.php            # Migration runner
│   └── migrations/            # Numbered SQL files
├── src/                       # PHP application code
│   ├── Config.php             # .env loader
│   ├── Db.php                 # PDO singleton
│   ├── Blockfrost/Client.php  # Blockfrost HTTP client
│   ├── Cip25/Validator.php    # CIP-25 schema validator
│   └── Sidecar/Client.php     # HTTP client for Node sidecar
├── sidecar/                   # Node.js/TypeScript Cardano sidecar
│   ├── package.json
│   ├── tsconfig.json
│   └── src/
│       ├── index.ts
│       └── routes/
├── qd_presales_template.csv   # Pre-marketplace sales ledger template
├── .env.example
├── .gitignore
└── rarefolio_marketplace_php_site_plan.md
```

## Next steps after Phase 1 lands

- Mint first asset on preprod using the dashboard + sidecar
- Begin Phase 2: Primary Sales + Ownership Index

See `rarefolio_marketplace_php_site_plan.md` (Unified Blueprint v2) for the full roadmap.
