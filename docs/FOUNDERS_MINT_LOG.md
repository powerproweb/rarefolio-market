# Founders Block 88 — Preprod Mint Log (Phase E.2)

**Network:** preprod
**Policy ID:** `dc77b92e4acd1887caa43df3f83c093a49990b96128cc8e02069ba4d`
**API base:** `https://market.rarefolio.io`
**Log started (UTC):** 2026-04-22

Verification per row: `GET /api/v1/tokens/{cnft_id}` must return
`status.primary_sale = "minted"` and a non-null `chain.mint_tx_hash`.

---

## #1 — qd-silver-0000705 — The Archivist
- Status: **minted + confirmed**
- Tx hash: `7cefff59c76515b5fd6e9992c7b245e1e4f607df0a84145256fe87337031aff8`
- minted_at (UTC): `2026-04-21 21:03:47`
- Verified via: `GET /api/v1/tokens/qd-silver-0000705` → `primary_sale=minted`
- Notes: Baseline from prior session; confirmed during 2026-04-22 preflight.

## #2 — qd-silver-0000706 — The Cartographer
- Status: **minted + confirmed**
- Tx hash: `6f6416e7daaeacd8225c93d5e4f8e23c78de9f29f6cb63d53b171768a987cd3c`
- minted_at (UTC): `2026-04-24 14:00:29`
- Verified via: `GET /api/v1/tokens/qd-silver-0000706` → `primary_sale=minted`
- Notes:

## #3 — qd-silver-0000707 — The Sentinel
- Status: **minted + confirmed**
- Tx hash: `e8e902f2325b165017676627641d213a73fd1857a7d19f723d4a4a6b081a8452`
- minted_at (UTC): _TBD_
- Verified via: `GET /api/v1/tokens/qd-silver-0000707` → `primary_sale=minted`
- Notes:

## #4 — qd-silver-0000708 — The Artisan
- Status: **minted + confirmed**
- Tx hash: `3d78dbae81030f4396b7b9d169c40c60b2255d28ef2f4958f00e53914ee6af68`
- minted_at (UTC): _TBD_
- Verified via: `GET /api/v1/tokens/qd-silver-0000708` → `primary_sale=minted`
- Notes:

## #5 — qd-silver-0000709 — The Scholar
- Status: **minted + confirmed**
- Tx hash: `943a5514c3d908bea998d51bab456546408add831578bb06f21097f5fb22d62b`
- minted_at (UTC): _TBD_
- Verified via: `GET /api/v1/tokens/qd-silver-0000709` → `primary_sale=minted`
- Notes:

## #6 — qd-silver-0000710 — The Ambassador
- Status: **minted + confirmed**
- Tx hash: `fd4bba05f5f939f982a2c72474fad4929b8b4433a84b4a9beb57a3d9ff45a97f`
- minted_at (UTC): _TBD_
- Verified via: `GET /api/v1/tokens/qd-silver-0000710` → `primary_sale=minted`
- Notes:

## #7 — qd-silver-0000711 — The Mentor
- Status: **minted + confirmed**
- Tx hash: `6d819d92b0c083ad0a1f2c4ca3a931bfe20ce9ded1dca0c0a5050e26d6df1e4d`
- minted_at (UTC): _TBD_
- Verified via: `GET /api/v1/tokens/qd-silver-0000711` → `primary_sale=minted`
- Notes:

## #8 — qd-silver-0000712 — The Architect
- Status: **minted + confirmed**
- Tx hash: `1e81aa97fdc26c1a718839d42a8d828ce22e51cb8a22f30fae832c1aafd65365`
- minted_at (UTC): `2026-04-24 14:43:18`
- Verified via: `GET /api/v1/tokens/qd-silver-0000712` → `primary_sale=minted`
- Notes:

---

## Phase E.2 completion gate
**GATE PASSED — 2026-04-24.** All 8 tokens minted and confirmed on preprod.
Next: Phase E.3 — finalize artwork JPGs, pin to IPFS, replace `REPLACE_WITH_CID` in migrations.
