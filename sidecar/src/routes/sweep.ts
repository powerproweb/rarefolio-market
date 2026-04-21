/**
 * Sweep routes — split wallet balance check and distribution trigger.
 *
 *   GET  /sweep/balance/:envKey
 *   POST /sweep/run
 *
 * Called by PHP (admin manual sweep or Blockfrost webhook handler).
 * Never called directly from the browser — runs on localhost only.
 */
import type { Express, Request, Response, NextFunction } from 'express';
import { z }                from 'zod';
import { runSweep, getSplitBalance } from '../lib/sweep.js';

const RecipientSchema = z.object({
    addr:  z.string().min(10),
    pct:   z.number().positive().max(100),
    label: z.string().min(1).max(128),
});

const SweepRunRequest = z.object({
    split_wallet_env_key: z.string().min(1).max(64),
    recipients:           z.array(RecipientSchema).min(1).max(20),
    min_lovelace:         z.number().int().positive().optional().default(20_000_000),
    submit:               z.boolean().optional().default(true),
});

export function mountSweepRoutes(app: Express): void {

    /**
     * GET /sweep/balance/:envKey
     *
     * Returns the current ADA balance of the split wallet for a given env key.
     * e.g. GET /sweep/balance/FOUNDERS → reads SPLIT_MNEMONIC_FOUNDERS
     *
     * Response:
     *   { env_key, wallet_addr, balance_lovelace, balance_ada }
     */
    app.get('/sweep/balance/:envKey', async (req: Request, res: Response, next: NextFunction) => {
        try {
            const envKey = String(req.params.envKey ?? '').toUpperCase();
            if (!envKey || !/^[A-Z0-9_]{1,64}$/.test(envKey)) {
                return res.status(400).json({ error: 'invalid env_key' });
            }
            const result = await getSplitBalance(envKey);
            res.json(result);
        } catch (err) {
            next(err);
        }
    });

    /**
     * POST /sweep/run
     *
     * Distributes ADA from a split wallet to configured recipients.
     * PHP passes the recipient list (from qd_royalty_recipients).
     *
     * Body:
     *   split_wallet_env_key  string     e.g. "FOUNDERS"
     *   recipients            array      [{addr, pct, label}] — pcts must sum to 100
     *   min_lovelace          number?    minimum balance before sweeping (default 20 ADA)
     *   submit                boolean?   true = broadcast; false = dry-run (default true)
     *
     * Response (SweepResult):
     *   { swept, balance_lovelace, min_lovelace, distributable_lovelace,
     *     distributions, tx_hash?, cbor_hex?, reason? }
     */
    app.post('/sweep/run', async (req: Request, res: Response, next: NextFunction) => {
        const parsed = SweepRunRequest.safeParse(req.body);
        if (!parsed.success) {
            return res.status(400).json({ error: 'invalid request', issues: parsed.error.issues });
        }

        const { split_wallet_env_key, recipients, min_lovelace, submit } = parsed.data;

        try {
            // zod-parsed array has optional-typed fields even though the schema requires them;
            // cast to the Recipient[] shape the sweep lib expects.
            const result = await runSweep(split_wallet_env_key, recipients as any, min_lovelace, submit);
            res.json(result);
        } catch (err) {
            next(err);
        }
    });
}
