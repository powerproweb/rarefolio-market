/**
 * RareFolio Cardano sidecar.
 *
 * Phase 1 scope:
 *   GET  /health              liveness probe
 *   GET  /asset/:unit         lookup asset via Blockfrost (includes CIP-25 metadata)
 *   POST /mint/prepare        stub — returns payload shape (real tx build in Phase 2)
 *   GET  /handle/:handle      ADA Handle -> address resolution stub
 */
import 'dotenv/config';
import express from 'express';
import { mountMintRoutes } from './routes/mint.js';
import { mountAssetRoutes } from './routes/asset.js';
import { mountHandleRoutes } from './routes/handle.js';

const app = express();
app.use(express.json({ limit: '512kb' }));

app.get('/health', (_req, res) => {
    res.json({
        ok: true,
        service: 'rarefolio-sidecar',
        version: '0.1.0',
        network: process.env.BLOCKFROST_NETWORK ?? 'preprod',
    });
});

mountAssetRoutes(app);
mountMintRoutes(app);
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
