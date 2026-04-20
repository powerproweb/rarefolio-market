/**
 * Payment routes — build unsigned ADA payment transactions for buyers.
 *
 *   POST /payment/prepare
 *
 * The buy page calls this to get an unsigned tx CBOR.
 * The buyer's CIP-30 wallet then signs and submits it.
 * No private keys needed — the sidecar only fetches UTxOs and builds.
 */
import type { Express, Request, Response, NextFunction } from 'express';
import { MeshTxBuilder, BlockfrostProvider } from '@meshsdk/core';
import { z } from 'zod';

const PaymentRequest = z.object({
    buyer_addr:      z.string().min(10),   // bech32 or hex-CBOR (CIP-30)
    recipient_addr:  z.string().min(10),   // split wallet address (bech32)
    amount_lovelace: z.number().int().positive().min(1_000_000), // min 1 ADA
});

function buildProvider(): BlockfrostProvider {
    const projectId = process.env.BLOCKFROST_API_KEY;
    if (!projectId) throw new Error('BLOCKFROST_API_KEY is not set');
    return new BlockfrostProvider(projectId);
}

function normAddr(raw: string): string {
    const t = raw.trim();
    return t.startsWith('0x') ? t.slice(2) : t;
}

export function mountPaymentRoutes(app: Express): void {

    /**
     * POST /payment/prepare
     *
     * Builds an unsigned Cardano transaction transferring ADA from the buyer
     * to the split wallet address.  The buyer's CIP-30 wallet signs it.
     *
     * Body:
     *   buyer_addr       string   bech32 or hex-CBOR of buyer's payment address
     *   recipient_addr   string   bech32 address of the split wallet
     *   amount_lovelace  number   exact amount to send (integer, ≥ 1 ADA)
     *
     * Response:
     *   { cbor_hex: string }   — unsigned tx ready for wallet.signTx(cbor, false)
     *
     * The buyer signs with `partialSign=false` (they are the sole signer for a
     * simple ADA transfer — no policy script needed).
     */
    app.post('/payment/prepare', async (req: Request, res: Response, next: NextFunction) => {
        const parsed = PaymentRequest.safeParse(req.body);
        if (!parsed.success) {
            return res.status(400).json({ error: 'invalid request', issues: parsed.error.issues });
        }

        const { buyer_addr, recipient_addr, amount_lovelace } = parsed.data;
        const buyerAddr     = normAddr(buyer_addr);
        const recipientAddr = normAddr(recipient_addr);

        try {
            const provider = buildProvider();

            // Fetch buyer's UTxOs for input selection
            const utxos = await provider.fetchAddressUTxOs(buyerAddr);
            if (!utxos || utxos.length === 0) {
                return res.status(422).json({
                    error: 'no_utxos',
                    message: 'No UTxOs found for the provided buyer address. '
                           + 'Make sure the wallet has ADA and is on the correct network.',
                });
            }

            // Build unsigned payment tx
            const txBuilder = new MeshTxBuilder({ fetcher: provider });
            const unsignedTx = await txBuilder
                .txOut(recipientAddr, [{ unit: 'lovelace', quantity: String(amount_lovelace) }])
                .changeAddress(buyerAddr)
                .selectUtxosFrom(utxos)
                .complete();

            console.log(
                `[payment/prepare] built unsigned tx: ${(amount_lovelace / 1_000_000).toFixed(6)} ADA ` +
                `→ ${recipientAddr.slice(0, 20)}…`
            );

            res.json({ cbor_hex: unsignedTx });
        } catch (err) {
            next(err);
        }
    });
}
