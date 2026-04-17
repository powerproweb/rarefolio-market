# RareFolio Cardano Sidecar

Node.js/TypeScript service that handles all Cardano-specific work so the PHP
application can stay agnostic of tx building and chain SDKs.

## Run it

```bash
cp .env.example .env
# edit .env: set BLOCKFROST_API_KEY at minimum

npm install
npm run dev
# -> listening on http://localhost:4000
```

## Endpoints

| Method | Path                          | Purpose                                      |
|--------|-------------------------------|----------------------------------------------|
| GET    | `/health`                     | Liveness probe                                |
| GET    | `/asset/:unit`                | Blockfrost asset lookup + current owner       |
| GET    | `/policy/:policyId/assets`    | Paginate all assets in a policy               |
| POST   | `/mint/prepare`               | Phase 1 stub: returns mint payload shape      |
| GET    | `/handle/:handle`             | ADA Handle -> address resolution (mainnet)    |

## Planned additions (Phase 2+)

- Real tx builder for mint, list, cancel, buy, offer, accept, bid, settle
- CIP-68 datum builder
- Aiken validator compilation + parameter injection
- IPFS pinning helper (Pinata / NFT.Storage)
- Blockfrost webhook receiver for settlement confirmation
