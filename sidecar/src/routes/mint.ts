import type { Express, Request, Response, NextFunction } from 'express';
import { Transaction, ForgeScript, BlockfrostProvider, type Mint } from '@meshsdk/core';
import { bf } from '../lib/blockfrost.js';
import {
    getPolicyWalletForKey,
    getPolicyIdForKey,
} from '../lib/policy.js';
import { z } from 'zod';

// Lazily-built Mesh BlockfrostProvider for fetching UTxOs during tx build.
let _meshProvider: BlockfrostProvider | null = null;
function meshProvider(): BlockfrostProvider {
    if (_meshProvider) return _meshProvider;
    const projectId = process.env.BLOCKFROST_API_KEY;
    if (!projectId) throw new Error('BLOCKFROST_API_KEY is not set');
    _meshProvider = new BlockfrostProvider(projectId);
    return _meshProvider;
}

// ---------------------------------------------------------------------------
// Request schemas
// ---------------------------------------------------------------------------

/**
 * POST /mint/prepare
 *
 * Builds and server-signs a real Cardano minting transaction.
 * The policy wallet (POLICY_MNEMONIC) pays the tx fee and provides the
 * policy-script witness.  The signed CBOR is returned for the caller to
 * either inspect or submit immediately via POST /mint/submit.
 *
 * recipient_addr  — bech32 (addr1...) OR hex-encoded CBOR from CIP-30
 *                   getUsedAddresses().  Both forms are accepted.
 */
const MintRequest = z.object({
    rarefolio_token_id: z.string().min(3).max(64),
    collection_slug:    z.string().min(1).max(64),
    asset_name_utf8:    z.string().min(1).max(64),
    recipient_addr:     z.string().min(10),        // bech32 or hex CBOR
    cip25:              z.record(z.any()),          // the per-token CIP-25 metadata
    policy_env_key:     z.string().max(64).optional(), // e.g. 'FOUNDERS' → POLICY_MNEMONIC_FOUNDERS
    lock_slot:          z.number().int().positive().nullable().optional(), // from qd_collections.lock_slot
});

/** POST /mint/submit — submits a signed tx CBOR via Blockfrost. */
const SubmitRequest = z.object({
    cbor_hex: z.string().min(10),
});

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/**
 * CIP-30's getUsedAddresses() returns addresses as hex-encoded CBOR.
 * Mesh expects bech32.  If the value doesn't start with 'addr', try to
 * decode it as a Blockfrost-style hex address.
 *
 * For now we accept it as-is; Mesh internally handles hex CBOR addresses
 * when fed through resolveDataHash or similar utilities.  If the address
 * starts with 'addr' it is already bech32 and passed through unchanged.
 */
function normaliseAddress(raw: string): string {
    const trimmed = raw.trim();
    if (trimmed.startsWith('addr')) return trimmed;          // already bech32
    // Hex CBOR — leave for Mesh to decode internally;
    // strip any leading 0x prefix that some wallets add.
    return trimmed.startsWith('0x') ? trimmed.slice(2) : trimmed;
}

// ---------------------------------------------------------------------------
// Route mounts
// ---------------------------------------------------------------------------

export function mountMintRoutes(app: Express): void {

    /**
     * GET /mint/policy-id
     *
     * Returns the policy ID derived from POLICY_MNEMONIC + POLICY_LOCK_SLOT.
     * Call this once before minting to record the policy_id in qd_tokens.
     */
    /**
     * GET /mint/policy-id?env_key=FOUNDERS
     *
     * env_key (optional): collection key. Defaults to legacy POLICY_MNEMONIC.
     * lock_slot (optional query param): include to show the script with a time-lock.
     */
    app.get('/mint/policy-id', (_req: Request, res: Response) => {
        try {
            const envKey   = String(_req.query.env_key   ?? '').toUpperCase() || undefined;
            const slotRaw  = _req.query.lock_slot ? Number(_req.query.lock_slot) : null;
            const lockSlot = (slotRaw && !isNaN(slotRaw)) ? slotRaw : null;

            const policyId   = getPolicyIdForKey(envKey, lockSlot);
            const policyAddr = getPolicyWalletForKey(envKey).getPaymentAddress();

            res.json({
                env_key:     envKey ?? 'POLICY_MNEMONIC',
                policy_id:   policyId,
                policy_addr: policyAddr,
                lock_slot:   lockSlot,
                script_type: lockSlot ? 'RequireAllOf(sig, before)' : 'RequireSignature',
            });
        } catch (err: unknown) {
            const msg = err instanceof Error ? err.message : String(err);
            res.status(500).json({ error: msg });
        }
    });

    /**
     * POST /mint/prepare
     *
     * Builds a real Cardano minting transaction using Mesh SDK, signs it with
     * the server-side policy wallet (POLICY_MNEMONIC), and returns the signed
     * CBOR.  The caller can pass this CBOR to POST /mint/submit.
     *
     * The policy wallet is the fee payer — fund it with ≥ 5 ADA before use.
     *
     * Body:
     *   rarefolio_token_id  string   e.g. "qd-silver-0000705"
     *   collection_slug     string   e.g. "silverbar-01-founders"
     *   asset_name_utf8     string   on-chain asset name (max 64 chars)
     *   recipient_addr      string   bech32 or hex-CBOR destination address
     *   cip25               object   CIP-25 per-token metadata
     *
     * Response:
     *   { cbor_hex, policy_id, asset_name_hex, stub: false }
     */
    app.post('/mint/prepare', async (req: Request, res: Response, next: NextFunction) => {
        const parsed = MintRequest.safeParse(req.body);
        if (!parsed.success) {
            return res.status(400).json({
                error:  'invalid mint request',
                issues: parsed.error.issues,
            });
        }

        const { rarefolio_token_id, collection_slug, asset_name_utf8, recipient_addr, cip25,
                policy_env_key, lock_slot } = parsed.data;
        const assetNameHex   = Buffer.from(asset_name_utf8, 'utf8').toString('hex');
        let   recipientBech  = normaliseAddress(recipient_addr);

        // Admin UI sends 'addr_test1qq_placeholder_recipient' when no real
        // recipient is supplied (it never prompts). Detect that and self-mint
        // to the policy wallet instead — correct default for test mints.
        const isPlaceholder =
            recipientBech === '' ||
            recipientBech.includes('_placeholder_') ||
            /[^a-z0-9]/.test(recipientBech.replace(/^addr(_test)?1/, ''));

        // Resolve collection-specific policy (falls back to POLICY_MNEMONIC if no key provided)
        const envKey = policy_env_key?.toUpperCase() || undefined;

        try {
            const wallet        = getPolicyWalletForKey(envKey);
            // Build the forging script as hex CBOR via Mesh's supported API.
            // NOTE: withOneSignature handles the simple 'RequireSignature' case.
            // Time-locked policies (lock_slot != null) will need a different
            // serialization path — not needed for initial mints.
            const paymentAddr   = wallet.getPaymentAddress();
            const forgingScript = ForgeScript.withOneSignature(paymentAddr);
            const policyId      = getPolicyIdForKey(envKey, lock_slot ?? null);

            // Swap to self-mint if the caller sent a placeholder recipient
            if (isPlaceholder) {
                console.log(`[mint/prepare] placeholder recipient detected — self-minting to policy wallet ${paymentAddr}`);
                recipientBech = paymentAddr;
            }

            // Wrap CIP-25 metadata in the standard 721 label structure:
            // { "<policyId>": { "<assetName>": { ...metadata... } } }
            const cip25Wrapped = {
                [policyId]: {
                    [asset_name_utf8]: cip25,
                },
            };

            const mintAsset: Mint = {
                assetName:     asset_name_utf8,
                assetQuantity: '1',
                label:         '721',
                metadata:      cip25,   // Mesh wraps this under the 721 label
                recipient:     recipientBech,
            };

            // Build a minimal IInitiator wrapper. AppWallet in Mesh 1.8.x
            // does NOT itself implement getUtxos/getChangeAddress; Transaction
            // expects those via the initiator. We bridge to BlockfrostProvider
            // for UTxO lookup and reuse the wallet's payment address as change.
            const provider = meshProvider();
            const initiator = {
                getUtxos:          async () => await provider.fetchAddressUTxOs(paymentAddr),
                getChangeAddress:  async () => paymentAddr,
                getCollateral:     async () => [] as any[],
                getUsedAddresses:  async () => [paymentAddr],
                signTx:            (hex: string) => wallet.signTx(hex),
                submitTx:          async (hex: string) => await provider.submitTx(hex),
            };

            const tx = new Transaction({ initiator: initiator as any });
            tx.mintAsset(forgingScript as any, mintAsset);
            // Explicitly attach the 721 label so wallets display the full metadata.
            tx.setMetadata(721, cip25Wrapped);

            const unsignedTx = await tx.build();
            const signedTx   = await wallet.signTx(unsignedTx);

            console.log(
                `[mint/prepare] built tx for ${rarefolio_token_id} ` +
                `(${asset_name_utf8}) policy=${policyId}`
            );

            res.json({
                stub:           false,
                cbor_hex:       signedTx,
                policy_id:      policyId,
                asset_name_hex: assetNameHex,
                asset_name_utf8,
                rarefolio_token_id,
                collection_slug,
                recipient_addr: recipientBech,
            });
        } catch (err) {
            next(err);
        }
    });

    /**
     * POST /mint/submit
     *
     * Submits a signed tx CBOR to the Cardano network via Blockfrost.
     * Returns the tx_hash on success.
     *
     * Body:
     *   { cbor_hex: string }
     *
     * Response:
     *   { tx_hash: string }
     */
    app.post('/mint/submit', async (req: Request, res: Response, next: NextFunction) => {
        const parsed = SubmitRequest.safeParse(req.body);
        if (!parsed.success) {
            return res.status(400).json({ error: 'missing cbor_hex', issues: parsed.error.issues });
        }

        try {
            const txHash = await bf().txSubmit(parsed.data.cbor_hex);
            console.log(`[mint/submit] submitted tx_hash=${txHash}`);
            res.json({ tx_hash: txHash });
        } catch (err) {
            next(err);
        }
    });
}
