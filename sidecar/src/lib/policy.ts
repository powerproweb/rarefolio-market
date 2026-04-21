/**
 * Policy script construction and derivation — multi-collection edition.
 *
 * Supports named policy and split wallets, one per collection.
 *
 * Env var naming convention (set in sidecar/.env):
 *   Policy wallet:  POLICY_MNEMONIC_{KEY}  e.g. POLICY_MNEMONIC_FOUNDERS
 *   Split wallet:   SPLIT_MNEMONIC_{KEY}   e.g. SPLIT_MNEMONIC_FOUNDERS
 *   Legacy (single collection): POLICY_MNEMONIC (no suffix)
 *
 * All named wallets are cached in a Map after first construction.
 *
 * Lock slot per collection comes from the DB (qd_collections.lock_slot) and is
 * passed in as a parameter. For the legacy single-collection path it falls back
 * to POLICY_LOCK_SLOT from env.
 */

import {
    AppWallet,
    BlockfrostProvider,
    deserializeAddress,
    resolveNativeScriptHash,
    type NativeScript,
} from '@meshsdk/core';

// ---------------------------------------------------------------------------
// Internal helpers
// ---------------------------------------------------------------------------

function networkId(): 0 | 1 {
    return process.env.BLOCKFROST_NETWORK === 'mainnet' ? 1 : 0;
}

function buildProvider(): BlockfrostProvider {
    const projectId = process.env.BLOCKFROST_API_KEY;
    if (!projectId) throw new Error('BLOCKFROST_API_KEY is not set');
    return new BlockfrostProvider(projectId);
}

function readMnemonic(envVarName: string): string[] {
    const raw = process.env[envVarName] ?? '';
    const words = raw.trim().split(/\s+/).filter(Boolean);
    if (words.length !== 24) {
        throw new Error(
            `${envVarName} must be a 24-word mnemonic (got ${words.length} words). ` +
            `Generate one with: npx @meshsdk/core generate-mnemonic`
        );
    }
    return words;
}

// ---------------------------------------------------------------------------
// Wallet cache  (Map<envVarName, AppWallet>)
// ---------------------------------------------------------------------------

const _walletCache = new Map<string, AppWallet>();

function getOrBuildWallet(envVarName: string): AppWallet {
    if (_walletCache.has(envVarName)) return _walletCache.get(envVarName)!;
    const provider = buildProvider();
    const wallet = new AppWallet({
        networkId: networkId(),
        fetcher:   provider,
        submitter: provider,
        key: { type: 'mnemonic', words: readMnemonic(envVarName) },
    });
    _walletCache.set(envVarName, wallet);
    return wallet;
}

// ---------------------------------------------------------------------------
// Named wallet accessors
// ---------------------------------------------------------------------------

/**
 * Returns the policy wallet for a collection env key.
 * e.g. getPolicyWalletForKey('FOUNDERS') reads POLICY_MNEMONIC_FOUNDERS.
 * getPolicyWalletForKey() with no arg reads the legacy POLICY_MNEMONIC.
 */
export function getPolicyWalletForKey(envKey?: string): AppWallet {
    const varName = envKey ? `POLICY_MNEMONIC_${envKey.toUpperCase()}` : 'POLICY_MNEMONIC';
    return getOrBuildWallet(varName);
}

/**
 * Returns the split (distribution) wallet for a collection env key.
 * e.g. getSplitWalletForKey('FOUNDERS') reads SPLIT_MNEMONIC_FOUNDERS.
 */
export function getSplitWalletForKey(envKey: string): AppWallet {
    return getOrBuildWallet(`SPLIT_MNEMONIC_${envKey.toUpperCase()}`);
}

// ---------------------------------------------------------------------------
// Native script helpers (per key)
// ---------------------------------------------------------------------------

/**
 * Builds the native script for a given policy key + optional lock slot.
 * lockSlot=null  → RequireSignature only
 * lockSlot=N     → RequireAllOf([sig, before(N)])
 */
export function getNativeScriptForKey(envKey?: string, lockSlot?: number | null): NativeScript {
    const wallet      = getPolicyWalletForKey(envKey);
    const paymentAddr = wallet.getPaymentAddress();
    // Derive the wallet's pub key hash and build the native script as JSON.
    // NOTE: earlier versions of this file used ForgeScript.withOneSignature()
    // which returns a hex-encoded forging script, not a NativeScript JSON.
    // That caused a broken cast and inability to compose into a time-locked 'all' script.
    const { pubKeyHash } = deserializeAddress(paymentAddr);
    const sigScript: NativeScript = { type: 'sig', keyHash: pubKeyHash };

    // Resolve lock slot: explicit param > env var (legacy path only)
    let slot: number | null = lockSlot ?? null;
    if (slot === undefined || slot === null) {
        if (!envKey) {
            // Legacy single-collection path: read from env
            const raw = process.env.POLICY_LOCK_SLOT?.trim();
            slot = raw ? parseInt(raw, 10) : null;
        }
    }

    if (!slot) return sigScript;

    if (isNaN(slot) || slot <= 0) {
        throw new Error(`lock_slot must be a positive integer (got ${slot})`);
    }

    return {
        type: 'all',
        scripts: [sigScript, { type: 'before', slot: String(slot) }],
    };
}

export function getPolicyIdForKey(envKey?: string, lockSlot?: number | null): string {
    return resolveNativeScriptHash(getNativeScriptForKey(envKey, lockSlot));
}

/**
 * Returns the NativeScript JSON for a collection. Mesh SDK's Transaction.mintAsset
 * accepts the NativeScript directly, no hex serialization needed.
 */
export function getForgingScriptForKey(envKey?: string, lockSlot?: number | null): NativeScript {
    return getNativeScriptForKey(envKey, lockSlot);
}

// ---------------------------------------------------------------------------
// Backward-compatible aliases (single-collection / no-key path)
// ---------------------------------------------------------------------------

/** @deprecated Use getPolicyWalletForKey() */
export function getPolicyWallet(): AppWallet  { return getPolicyWalletForKey(); }
/** @deprecated Use getNativeScriptForKey() */
export function getNativeScript(): NativeScript { return getNativeScriptForKey(); }
/** @deprecated Use getPolicyIdForKey() */
export function getPolicyId(): string          { return getPolicyIdForKey(); }
/** @deprecated Use getForgingScriptForKey() */
export function getForgingScript(): NativeScript { return getForgingScriptForKey(); }
