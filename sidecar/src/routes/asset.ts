import type { Express, Request, Response, NextFunction } from 'express';
import { bf } from '../lib/blockfrost.js';

export function mountAssetRoutes(app: Express): void {
    /**
     * GET /asset/:unit
     *   where :unit = policy_id + asset_name_hex (concatenated hex string)
     *
     * Returns the Blockfrost asset record plus current owner address.
     */
    app.get('/asset/:unit', async (req: Request, res: Response, next: NextFunction) => {
        try {
            const { unit } = req.params;
            if (!/^[0-9a-fA-F]{56,}$/.test(unit)) {
                return res.status(400).json({ error: 'invalid unit (expected hex of >=56 chars)' });
            }

            const api = bf();
            const asset = await api.assetsById(unit).catch((e) => {
                if ((e as { status_code?: number }).status_code === 404) return null;
                throw e;
            });

            if (!asset) return res.status(404).json({ error: 'asset not found' });

            // Current owner (for NFTs the list has 1 entry with quantity=1)
            const holders = await api.assetsAddresses(unit).catch(() => []);
            const current = holders.find((h) => Number(h.quantity) > 0);

            res.json({
                unit,
                policy_id: asset.policy_id,
                asset_name: asset.asset_name,
                fingerprint: asset.fingerprint,
                quantity: asset.quantity,
                initial_mint_tx_hash: asset.initial_mint_tx_hash,
                mint_or_burn_count: asset.mint_or_burn_count,
                onchain_metadata: asset.onchain_metadata,
                onchain_metadata_standard: asset.onchain_metadata_standard,
                metadata: asset.metadata,
                current_owner: current?.address ?? null,
            });
        } catch (err) {
            next(err);
        }
    });

    /**
     * GET /policy/:policyId/assets?page=1&count=100
     */
    app.get('/policy/:policyId/assets', async (req, res, next) => {
        try {
            const { policyId } = req.params;
            const page  = Number(req.query.page  ?? 1);
            const count = Number(req.query.count ?? 100);
            const assets = await bf().assetsPolicyByIdAll(policyId);
            // crude in-memory pagination
            const start = (page - 1) * count;
            res.json({ policy_id: policyId, page, count, assets: assets.slice(start, start + count) });
        } catch (err) {
            next(err);
        }
    });
}
