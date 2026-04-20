/**
 * Policy script construction and derivation.
 *
 * Builds a Cardano native script from the server-side POLICY_MNEMONIC and
 * (optionally) POLICY_LOCK_SLOT, then exposes helpers consumed by the mint
 * route.
 *
 * Two script types are supported:
 *
 *   1. RequireSignature (no POLICY_LOCK_SLOT set):
 *      { type: "sig", keyHash: <payment-key-hash> }
 *
 *   2. RequireAllOf + time-lock (POLICY_LOCK_SLOT set):
 *      { type: "all", scripts: [
 *          { type: "sig",    keyHash: <payment-key-hash> },
 *          { type: "before", slot: <POLICY_LOCK_SLOT>    },
 *      ]}
 *
 * The policy ID is the hash of the serialised native script. It is stable for
 * the lifetime of the key + slot combination, so it must be recorded in
 * qd_tokens.policy_id before the first mint and never changed.
 */

import {
    AppWallet,
    BlockfrostProvider,
    ForgeScript,
    resolveNativeScriptHash,
    type NativeScript,
} from '@meshsdk/core';

// ---------------------------------------------------------------------------
// Internal helpers
// ---------------------------------------------------------------------------

function networkId(): 0 | 1 {
    return process.env.BLOCKFROST_NETWORK === 'mainnet' ? 1 : 0;
}

function requireMnemonic(): string[] {
    const raw = process.env.POLICY_MNEMONIC ?? '';
    const words = raw.trim().split(/\s+/).filter(Boolean);
    if (words.length !== 24) {
        throw new Error(
            `POLICY_MNEMONIC must be a 24-word mnemonic (got ${words.length} words). ` +
            `Generate one with: npx @meshsdk/core generate-mnemonic`
        );
    }
    return words;
}

// ---------------------------------------------------------------------------
// Lazy singletons
// ---------------------------------------------------------------------------

let _wallet: AppWallet | null = null;

/**
 * Returns the policy AppWallet singleton.
 * Throws if POLICY_MNEMONIC is not set or malformed.
 */
export function getPolicyWallet(): AppWallet {
    if (_wallet) return _wallet;

    // BlockfrostProvider from the already-initialised bf() instance isn't
    // directly reusable here because AppWallet needs the raw provider.
    const projectId = process.env.BLOCKFROST_API_KEY;
    if (!projectId) throw new Error('BLOCKFROST_API_KEY is not set');

    const provider = new BlockfrostProvider(projectId);

    _wallet = new AppWallet({
        networkId: networkId(),
        fetcher: provider,
        signer: {
            type: 'Mnemonic',
            words: requireMnemonic(),
        },
    });

    return _wallet;
}

/**
 * Returns the NativeScript object for this policy.
 * Deterministic for the same POLICY_MNEMONIC + POLICY_LOCK_SLOT combination.
 */
export function getNativeScript(): NativeScript {
    const wallet    = getPolicyWallet();
    const paymentAddr = wallet.getPaymentAddress();

    // Base: RequireSignature from the policy wallet's payment key
    const sigScript: NativeScript = ForgeScript.withOneSignature(paymentAddr) as NativeScript;

    const lockSlot = process.env.POLICY_LOCK_SLOT?.trim();
    if (!lockSlot) return sigScript;

    const slot = parseInt(lockSlot, 10);
    if (isNaN(slot) || slot <= 0) {
        throw new Error(`POLICY_LOCK_SLOT must be a positive integer (got "${lockSlot}")`);
    }

    // RequireAllOf([sig, before(slot)])
    return {
        type: 'all',
        scripts: [
            sigScript,
            { type: 'before', slot },
        ],
    } as NativeScript;
}

/**
 * Returns the hex policy ID derived from the native script.
 * This is what goes into qd_tokens.policy_id.
 */
export function getPolicyId(): string {
    return resolveNativeScriptHash(getNativeScript());
}

/**
 * Serialises the native script to the string form expected by Mesh's
 * Transaction.mintAsset() as the "forging script".
 */
export function getForgingScript(): string {
    // ForgeScript.withOneSignature already returns the serialised form.
    // For the compound script we serialise via JSON (Mesh accepts this format).
    const script = getNativeScript();
    return typeof script === 'string' ? script : JSON.stringify(script);
}
