import type { Express } from 'express';
import { z } from 'zod';

/**
 * POST /mint/prepare
 *
 * Phase 1: returns the *shape* of the payload the wallet will be asked to sign.
 *          Real tx building lands in Phase 2 once the Cardano tx builder
 *          (lucid-cardano / @meshsdk/core / serialization-lib) is wired in.
 */
const MintRequest = z.object({
    rarefolio_token_id: z.string().regex(/^RF-\d{4,6}$/),
    collection_slug:    z.string().min(1),
    policy_id:          z.string().regex(/^[0-9a-f]{56}$/i).optional(),
    asset_name_utf8:    z.string().min(1).max(64),
    recipient_addr:     z.string().startsWith('addr'),
    cip25:              z.record(z.any()), // the wrapped 721 object
});

export function mountMintRoutes(app: Express): void {
    app.post('/mint/prepare', (req, res) => {
        const parsed = MintRequest.safeParse(req.body);
        if (!parsed.success) {
            return res.status(400).json({ error: 'invalid mint request', issues: parsed.error.issues });
        }
        const {
            rarefolio_token_id,
            asset_name_utf8,
            recipient_addr,
            policy_id,
            cip25,
        } = parsed.data;

        const asset_name_hex = Buffer.from(asset_name_utf8, 'utf8').toString('hex');

        // PHASE 1 STUB — return a descriptive envelope so the admin UI can verify
        // the full pipeline is wired, end-to-end. Phase 2 replaces `cbor_hex`
        // with a real, unsigned transaction produced by the tx builder.
        res.json({
            stub: true,
            note: 'Phase 1 stub. Real tx building lands in Phase 2 (lucid/mesh + Aiken).',
            request: {
                rarefolio_token_id,
                policy_id: policy_id ?? null,
                asset_name_utf8,
                asset_name_hex,
                recipient_addr,
            },
            cip25,
            next_steps: [
                'Sign off-chain policy script + derive policy_id',
                'Lock CIP-27 royalty token on policy (rate 0.08, addr creator)',
                'Build unsigned minting tx with native script witness',
                'Return cbor_hex for wallet to sign via CIP-30',
            ],
        });
    });
}
