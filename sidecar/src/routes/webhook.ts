/**
 * Inbound Blockfrost webhook receiver.
 *
 * POST /webhooks/blockfrost
 *
 * Blockfrost sends this when a registered address receives a transaction.
 * We validate the HMAC signature then return 200 immediately.
 * The PHP layer handles the actual sweep trigger (it has DB access).
 *
 * Blockfrost-Signature header format:
 *   t=<unix_timestamp>,v1=<hmac_sha256_hex>
 *
 * Payload signed: "<timestamp>.<raw_body_string>"
 * Secret:         BLOCKFROST_WEBHOOK_AUTH_TOKEN in sidecar .env
 *
 * IMPORTANT: This route must receive the raw (unparsed) body for signature
 * verification. It uses express.raw() as middleware before JSON.parse().
 */
import { createHmac, timingSafeEqual } from 'node:crypto';
import type { Express, Request, Response } from 'express';
import express from 'express';

const MAX_SKEW_SECONDS = 300;   // reject webhooks more than 5 minutes old

export function mountWebhookRoutes(app: Express): void {

    /**
     * POST /webhooks/blockfrost
     *
     * Body: raw Blockfrost webhook payload (JSON text, NOT pre-parsed)
     *
     * Returns 200 immediately on valid signature (even if we can't sweep yet).
     * Returns 401 on invalid signature.
     * Returns 400 on missing/malformed headers.
     *
     * NOTE: The PHP endpoint (api/webhooks/blockfrost.php) is the public-facing
     * receiver. This sidecar route is an alternative if the sidecar is publicly
     * accessible. In the standard deployment, PHP receives, validates, and calls
     * POST /sweep/run on this sidecar.
     */
    app.post(
        '/webhooks/blockfrost',
        express.raw({ type: '*/*' }),   // capture raw body BEFORE json parsing
        (req: Request, res: Response) => {

            const secret = process.env.BLOCKFROST_WEBHOOK_AUTH_TOKEN?.trim();
            if (!secret) {
                console.error('[webhook] BLOCKFROST_WEBHOOK_AUTH_TOKEN not set — rejecting');
                return res.status(500).json({ error: 'webhook auth token not configured' });
            }

            // Parse the signature header
            const sigHeader = String(req.headers['blockfrost-signature'] ?? '');
            if (!sigHeader) {
                return res.status(400).json({ error: 'missing Blockfrost-Signature header' });
            }

            const parts: Record<string, string> = {};
            for (const segment of sigHeader.split(',')) {
                const idx = segment.indexOf('=');
                if (idx > 0) parts[segment.slice(0, idx)] = segment.slice(idx + 1);
            }

            const timestamp = parts['t'];
            const signature = parts['v1'];
            if (!timestamp || !signature) {
                return res.status(400).json({ error: 'malformed Blockfrost-Signature header' });
            }

            // Replay protection: reject stale webhooks
            const age = Math.abs(Date.now() / 1000 - Number(timestamp));
            if (age > MAX_SKEW_SECONDS) {
                return res.status(400).json({ error: `webhook timestamp too old (${Math.floor(age)}s)` });
            }

            // Compute expected HMAC
            const rawBody    = req.body instanceof Buffer ? req.body.toString('utf8') : String(req.body);
            const payload    = `${timestamp}.${rawBody}`;
            const computed   = createHmac('sha256', secret).update(payload).digest('hex');

            let sigBuf: Buffer;
            let cmpBuf: Buffer;
            try {
                sigBuf = Buffer.from(signature, 'hex');
                cmpBuf = Buffer.from(computed,  'hex');
            } catch {
                return res.status(401).json({ error: 'invalid signature encoding' });
            }

            if (sigBuf.length !== cmpBuf.length || !timingSafeEqual(sigBuf, cmpBuf)) {
                return res.status(401).json({ error: 'invalid signature' });
            }

            // Signature valid — parse body and log
            let parsed: unknown;
            try {
                parsed = JSON.parse(rawBody);
            } catch {
                return res.status(400).json({ error: 'invalid JSON body' });
            }

            const type    = (parsed as Record<string, unknown>)['type'] ?? 'unknown';
            console.log(`[webhook/blockfrost] received event type=${type} ts=${timestamp}`);

            // Return 200 immediately. The PHP layer (api/webhooks/blockfrost.php)
            // is responsible for the sweep trigger when it's the public receiver.
            // If this sidecar IS the public receiver, add sweep logic here.
            res.json({ ok: true, type });
        }
    );
}
