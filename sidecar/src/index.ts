/**
 * RareFolio Cardano sidecar  —  Phase 2
 *
 * Routes:
 *   GET  /health                  liveness probe
 *   GET  /asset/:unit             Blockfrost asset lookup + CIP-25 metadata
 *   GET  /policy/:policyId/assets all assets under a policy
 *   POST /mint/prepare            build + sign a real Cardano minting tx
 *   POST /mint/submit             submit a signed tx CBOR via Blockfrost
 *   GET  /mint/policy-id          derive policy ID from POLICY_MNEMONIC
 *   GET  /sync/token/:unit        current owner lookup for a single asset
 *   GET  /sync/policy/:policyId   bulk ownership sync for a full policy
 *   GET  /handle/:handle          ADA Handle -> address resolution
 */
import 'dotenv/config';
import express from 'express';
import { mountMintRoutes }    from './routes/mint.js';
import { mountAssetRoutes }   from './routes/asset.js';
import { mountHandleRoutes }  from './routes/handle.js';
import { mountSyncRoutes }    from './routes/sync.js';
import { mountSweepRoutes }   from './routes/sweep.js';
import { mountWebhookRoutes } from './routes/webhook.js';

const VERSION = '0.2.0';

const app = express();
app.use(express.json({ limit: '512kb' }));

app.get('/health', (_req, res) => {
    const policyConfigured = Boolean(process.env.POLICY_MNEMONIC?.trim());
    res.json({
        ok:               true,
        service:          'rarefolio-sidecar',
        version:          VERSION,
        network:          process.env.BLOCKFROST_NETWORK ?? 'preprod',
        policy_ready:     policyConfigured,
    });
});

mountAssetRoutes(app);
mountMintRoutes(app);
mountSyncRoutes(app);
mountSweepRoutes(app);
mountWebhookRoutes(app);
mountHandleRoutes(app);

// Generic 404
app.use((_req, res) => res.status(404).json({ error: 'Not found' }));

// Generic error handler
// eslint-disable-next-line @typescript-eslint/no-unused-vars
app.use((err: Error, _req: express.Request, res: express.Response, _next: express.NextFunction) => {
    console.error('[sidecar] unhandled:', err);
    res.status(500).json({ error: err.message ?? 'Internal error' });
});

const port = Number(process.env.PORT ?? 4000);
app.listen(port, () => {
    console.log(`[sidecar] listening on http://localhost:${port}`);
    console.log(`[sidecar] network=${process.env.BLOCKFROST_NETWORK ?? 'preprod'}`);
    if (!process.env.BLOCKFROST_API_KEY) {
        console.warn('[sidecar] WARNING: BLOCKFROST_API_KEY is not set — calls will fail.');
    }
});
