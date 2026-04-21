/**
 * Split wallet sweep — distribute ADA from a collection's split wallet
 * to its configured royalty recipients in a single Cardano transaction.
 *
 * Flow:
 *   1. Load the split wallet from SPLIT_MNEMONIC_{UPPER(envKey)}
 *   2. Check balance against minLovelace threshold
 *   3. Calculate proportional distributions (pcts must sum to 100)
 *   4. Verify every output meets Cardano's minimum UTxO (~1.5 ADA)
 *   5. Build a single multi-output ADA transaction
 *   6. Sign with the split wallet
 *   7. Submit via Blockfrost (optional — set submit=false for dry-run)
 */

import { Transaction, BlockfrostProvider, type UTxO } from '@meshsdk/core';
import { bf }           from './blockfrost.js';
import { getSplitWalletForKey } from './policy.js';

// Lazily-built Mesh provider for fetching UTxOs in Mesh's expected shape.
let _meshProvider: BlockfrostProvider | null = null;
function meshProvider(): BlockfrostProvider {
    if (_meshProvider) return _meshProvider;
    const projectId = process.env.BLOCKFROST_API_KEY;
    if (!projectId) throw new Error('BLOCKFROST_API_KEY is not set');
    _meshProvider = new BlockfrostProvider(projectId);
    return _meshProvider;
}

// ---------------------------------------------------------------------------
// Types
// ---------------------------------------------------------------------------

export interface Recipient {
    addr:  string;
    pct:   number;   // share of the distributable pool, must sum to 100
    label: string;
}

export interface Distribution {
    addr:     string;
    label:    string;
    pct:      number;
    lovelace: number;
}

export interface SweepResult {
    swept:              boolean;
    balance_lovelace:   number;
    min_lovelace:       number;
    distributable_lovelace?: number;
    distributions?:     Distribution[];
    cbor_hex?:          string;   // present when submit=false
    tx_hash?:           string;   // present when submit=true and succeeded
    reason?:            string;   // why not swept (balance below min)
}

// Cardano minimum lovelace per pure-ADA UTxO output (~1.2–2 ADA depending on era)
const MIN_OUTPUT_LOVELACE = 1_500_000;

// Conservative fee buffer deducted before distributing
const FEE_BUFFER_LOVELACE = 500_000;   // 0.5 ADA

// ---------------------------------------------------------------------------
// Core sweep function
// ---------------------------------------------------------------------------

/**
 * Run a sweep for the given split wallet env key.
 *
 * @param envKey       Collection key — reads SPLIT_MNEMONIC_{KEY} from env
 * @param recipients   Recipient list (pcts must sum to 100)
 * @param minLovelace  Skip sweep if balance is below this threshold
 * @param submit       true = broadcast via Blockfrost; false = return cbor_hex only
 */
export async function runSweep(
    envKey:     string,
    recipients: Recipient[],
    minLovelace: number,
    submit      = true,
): Promise<SweepResult> {

    const wallet = getSplitWalletForKey(envKey);
    const walletAddr = wallet.getPaymentAddress();

    // ------------------------------------------------------------------
    // 1. Check balance (fetch UTxOs via BlockfrostProvider — Mesh shape)
    // ------------------------------------------------------------------
    const utxos: UTxO[] = await meshProvider().fetchAddressUTxOs(walletAddr);
    const balanceLovelace = utxos.reduce((sum: number, u: UTxO) => {
        const lovelace = u.output.amount.find((a: { unit: string }) => a.unit === 'lovelace');
        return sum + Number(lovelace?.quantity ?? 0);
    }, 0);

    if (balanceLovelace < minLovelace) {
        return {
            swept:            false,
            balance_lovelace: balanceLovelace,
            min_lovelace:     minLovelace,
            reason: `Balance ${(balanceLovelace / 1_000_000).toFixed(6)} ADA is below the ` +
                    `${(minLovelace / 1_000_000).toFixed(6)} ADA minimum sweep threshold.`,
        };
    }

    // ------------------------------------------------------------------
    // 2. Validate recipients
    // ------------------------------------------------------------------
    if (!recipients.length) {
        throw new Error('recipients list is empty');
    }
    const totalPct = recipients.reduce((s, r) => s + r.pct, 0);
    if (Math.abs(totalPct - 100) > 0.01) {
        throw new Error(`recipient percentages must sum to 100 (got ${totalPct.toFixed(4)})`);
    }

    // ------------------------------------------------------------------
    // 3. Calculate distributions
    // ------------------------------------------------------------------
    const distributable = balanceLovelace - FEE_BUFFER_LOVELACE;

    const distributions: Distribution[] = recipients.map(r => ({
        addr:     r.addr,
        label:    r.label,
        pct:      r.pct,
        lovelace: Math.floor(distributable * (r.pct / 100)),
    }));

    // Verify all outputs meet Cardano's minimum
    const belowMin = distributions.filter(d => d.lovelace < MIN_OUTPUT_LOVELACE);
    if (belowMin.length > 0) {
        throw new Error(
            `Some recipients would receive below the ${(MIN_OUTPUT_LOVELACE / 1_000_000)} ADA ` +
            `Cardano minimum UTxO. Increase the sweep threshold or reduce recipient count. ` +
            `Affected: ${belowMin.map(d => `${d.label} (${d.lovelace} lovelace)`).join(', ')}`
        );
    }

    // ------------------------------------------------------------------
    // 4. Build transaction
    // ------------------------------------------------------------------
    // AppWallet implements IInitiator at runtime but 1.8.x type decls don't declare it;
    // cast to any to unblock tsc. Runtime behaviour is correct.
    const tx = new Transaction({ initiator: wallet as any });
    for (const d of distributions) {
        tx.sendLovelace(d.addr, String(d.lovelace));
    }

    const unsignedTx = await tx.build();
    const signedTx   = await wallet.signTx(unsignedTx);

    // ------------------------------------------------------------------
    // 5. Submit or return
    // ------------------------------------------------------------------
    if (!submit) {
        return {
            swept:                 true,
            balance_lovelace:      balanceLovelace,
            min_lovelace:          minLovelace,
            distributable_lovelace: distributable,
            distributions,
            cbor_hex:              signedTx,
        };
    }

    const txHash = await bf().txSubmit(signedTx);
    console.log(`[sweep] distributed ${(distributable / 1_000_000).toFixed(6)} ADA ` +
                `from SPLIT_MNEMONIC_${envKey.toUpperCase()} → tx_hash=${txHash}`);

    return {
        swept:                 true,
        balance_lovelace:      balanceLovelace,
        min_lovelace:          minLovelace,
        distributable_lovelace: distributable,
        distributions,
        tx_hash:               txHash,
    };
}

// ---------------------------------------------------------------------------
// Balance check (no transaction)
// ---------------------------------------------------------------------------

export async function getSplitBalance(envKey: string): Promise<{
    env_key:          string;
    wallet_addr:      string;
    balance_lovelace: number;
    balance_ada:      number;
}> {
    const wallet = getSplitWalletForKey(envKey);
    const addr   = wallet.getPaymentAddress();
    const utxos: UTxO[]  = await meshProvider().fetchAddressUTxOs(addr);
    const balance = utxos.reduce((sum: number, u: UTxO) => {
        const lovelace = u.output.amount.find((a: { unit: string }) => a.unit === 'lovelace');
        return sum + Number(lovelace?.quantity ?? 0);
    }, 0);
    return {
        env_key:          envKey.toUpperCase(),
        wallet_addr:      addr,
        balance_lovelace: balance,
        balance_ada:      balance / 1_000_000,
    };
}
