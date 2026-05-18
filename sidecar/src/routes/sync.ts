/**
 * Ownership sync routes.
 *
 * PHP admin calls these to pull current on-chain ownership data from
 * Blockfrost and update qd_tokens.current_owner_wallet.
 *
 * Routes:
 *   GET /sync/token/:unit   — unit = policy_id + asset_name_hex (concatenated)
 *   GET /sync/policy/:policyId — all assets under a policy
 */
import type { Express, Request, Response, NextFunction } from 'express';
import { bf } from '../lib/blockfrost.js';
type BlockfrostErrorShape = {
    status_code?: number;
    statusCode?: number;
    message?: string;
    response?: {
        status?: number;
        data?: {
            status_code?: number;
            statusCode?: number;
        };
    };
};

function blockfrostStatus(err: unknown): number | null {
    const e = err as BlockfrostErrorShape;
    const status =
        e?.status_code ??
        e?.statusCode ??
        e?.response?.status ??
        e?.response?.data?.status_code ??
        e?.response?.data?.statusCode;
    return typeof status === 'number' ? status : null;
}

function isBlockfrostConfigError(err: unknown): boolean {
    return err instanceof Error && /BLOCKFROST_API_KEY is not set/i.test(err.message);
}

export function mountSyncRoutes(app: Express): void {

    /**
     * GET /sync/token/:unit
     *
     * Looks up the current owner address for a single asset.
     * `unit` must be `policyId + assetNameHex` (56 + N hex chars).
     *
     * Response:
     *   {
     *     unit:          string,
     *     policy_id:     string,
     *     asset_name:    string,
     *     fingerprint:   string | null,
     *     current_owner: string | null,   // bech32 address or null if not found
     *     quantity:      string,
     *   }
     */
    app.get('/sync/token/:unit', async (req: Request, res: Response, next: NextFunction) => {
        try {
            const { unit } = req.params;
            if (!/^[0-9a-fA-F]{56,}$/.test(unit)) {
                return res.status(400).json({ error: 'invalid unit (expected policy_id + asset_name_hex, min 56 hex chars)' });
            }
            let api: ReturnType<typeof bf>;
            try {
                api = bf();
            } catch (e) {
                if (isBlockfrostConfigError(e)) {
                    return res.status(503).json({ error: 'blockfrost is not configured' });
                }
                throw e;
            }

            let asset;
            try {
                asset = await api.assetsById(unit);
            } catch (e) {
                if (blockfrostStatus(e) === 404) {
                    return res.status(404).json({ error: 'asset not found on chain' });
                }
                throw e;
            }

            if (!asset) {
                return res.status(404).json({ error: 'asset not found on chain' });
            }

            const holders = await api.assetsAddresses(unit).catch(() => []);
            const current = holders.find((h) => Number(h.quantity) > 0);

            res.json({
                unit,
                policy_id:     asset.policy_id,
                asset_name:    asset.asset_name,
                fingerprint:   asset.fingerprint ?? null,
                current_owner: current?.address ?? null,
                quantity:      asset.quantity,
            });
        } catch (err) {
            next(err);
        }
    });

    /**
     * GET /sync/policy/:policyId
     *
     * Returns all assets under a policy with their current owners.
     * Useful for bulk reconciliation of an entire collection.
     *
     * Query params:
     *   page  (default 1)
     *   count (default 100, max 100 per Blockfrost page)
     */
    app.get('/sync/policy/:policyId', async (req: Request, res: Response, next: NextFunction) => {
        try {
            const { policyId } = req.params;
            if (!/^[0-9a-fA-F]{56}$/.test(policyId)) {
                return res.status(400).json({ error: 'invalid policyId (must be 56 hex chars)' });
            }

            const page  = Math.max(1, Number(req.query.page  ?? 1));
            const count = Math.min(100, Math.max(1, Number(req.query.count ?? 100)));
            let api: ReturnType<typeof bf>;
            try {
                api = bf();
            } catch (e) {
                if (isBlockfrostConfigError(e)) {
                    return res.status(503).json({ error: 'blockfrost is not configured' });
                }
                throw e;
            }
            const assets = await api.assetsPolicyByIdAll(policyId);
            const paged  = assets.slice((page - 1) * count, page * count);

            // Resolve current owner for each asset (sequential to avoid rate-limit)
            const results = [];
            for (const a of paged) {
                const holders = await api.assetsAddresses(a.asset).catch(() => []);
                const current = holders.find((h) => Number(h.quantity) > 0);
                results.push({
                    unit:          a.asset,
                    quantity:      a.quantity,
                    current_owner: current?.address ?? null,
                });
            }

            res.json({
                policy_id: policyId,
                page,
                count,
                total:     assets.length,
                assets:    results,
            });
        } catch (err) {
            next(err);
        }
    });
}
