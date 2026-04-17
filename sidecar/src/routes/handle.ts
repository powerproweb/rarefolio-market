import type { Express } from 'express';
import { bf } from '../lib/blockfrost.js';

/**
 * ADA Handle resolution.
 *
 * Each handle is a CNFT under the canonical Handle policy:
 *   mainnet: f0ff48bbb7bbe9d59a40f1ce90e9e9d0ff5002ec48f232b49ca0fb9a
 *   preprod: (test handles are also on the same policy on mainnet; preprod usage is limited)
 *
 * We look up the asset `policy_id + hex(handle)` and return the current holder.
 */
const HANDLE_POLICY_MAINNET = 'f0ff48bbb7bbe9d59a40f1ce90e9e9d0ff5002ec48f232b49ca0fb9a';

export function mountHandleRoutes(app: Express): void {
    app.get('/handle/:handle', async (req, res, next) => {
        try {
            const raw = String(req.params.handle ?? '');
            const normalized = raw.replace(/^\$/, '').toLowerCase().trim();
            if (!/^[a-z0-9._-]{1,15}$/.test(normalized)) {
                return res.status(400).json({ error: 'invalid handle format' });
            }

            const network = process.env.BLOCKFROST_NETWORK ?? 'preprod';
            if (network !== 'mainnet') {
                // ADA Handle is mainnet-only; return a clear signal
                return res.json({
                    handle: normalized,
                    resolved_addr: null,
                    note: 'ADA Handle resolution is only reliable on mainnet; current network is ' + network,
                });
            }

            const unit = HANDLE_POLICY_MAINNET + Buffer.from(normalized, 'utf8').toString('hex');
            const holders = await bf().assetsAddresses(unit).catch(() => []);
            const current = holders.find((h) => Number(h.quantity) > 0);

            res.json({
                handle: normalized,
                unit,
                resolved_addr: current?.address ?? null,
            });
        } catch (err) {
            next(err);
        }
    });
}
