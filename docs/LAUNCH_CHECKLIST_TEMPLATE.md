# Collection Launch Checklist Template
**Collection:** `<COLLECTION_NAME>`
**Collection slug:** `<COLLECTION_SLUG>`
**Bar serial:** `<BAR_SERIAL>`
**Block ID:** `<BLOCK_ID>`
**Batch:** `<BATCH_NUM>`
**Status:** `<NOT_STARTED|IN_PROGRESS|READY>`
## Phase A, Creative and Story
- [ ] Final artwork files exported and named with final token IDs
- [ ] Story mode selected (`shared` or `per_item`)
- [ ] Shared story finalized
- [ ] Per-item stories finalized (if `per_item`)
## Phase B, Files and Seeds
- [ ] Main site block seed SQL created:
  - `api/sql/seed_<collection>_blocks.sql`
- [ ] Main site stories seed SQL created:
  - `api/sql/seed_<collection>_stories.sql`
- [ ] Market token seed SQL created:
  - `db/migrations/<NNN>_seed_<collection>_tokens.sql`
- [ ] Asset folder populated:
  - `assets/img/collection/<folder_slug>/`
- [ ] Story fallback files populated:
  - `assets/stories/<story_folder>/`
## Phase C, Contract Validation
- [ ] Contract config created:
  - `tests/collection-contracts/<collection>.json`
- [ ] Static contract validator pass:
  - `php tests/test_collection_contract_static.php --config=tests/collection-contracts/<collection>.json`
- [ ] Collection slug and token IDs match across all files
## Phase D, Preprod Mint Gate
- [ ] Sidecar and API health checks pass
- [ ] Canary token minted and confirmed
- [ ] Mint webhook entry confirmed for canary
- [ ] Full set minted in preprod and confirmed
- [ ] API token state checks pass for all tokens
## Phase E, Mainnet Gate
- [ ] Mainnet network and keys confirmed
- [ ] Mainnet policy wallet funded
- [ ] Mainnet mint completed for full set
- [ ] Mint tx hashes recorded
- [ ] Certificate issuance completed
- [ ] `nft`, `cert`, `verify`, and `claim-download` surfaces verified
## Phase F, Release Evidence Gate
- [ ] Drift guard passes for full token set
- [ ] One fresh signed challenge and claim-download success captured
- [ ] Release evidence bundle marked green
- [ ] Launch announcement approved and published
