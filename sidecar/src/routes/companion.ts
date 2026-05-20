/**
 * Companion FT routes.
 *
 *   GET  /companion/treasury/:envKey/balance
 *   GET  /companion/treasury/:envKey/unit/:unit/balance
 *   POST /companion/transfer
 *   POST /companion/transfer-paired
 *
 * Used by marketplace PHP to disburse companion assets from a
 * server-controlled treasury wallet to a collector after settlement.
 */
import type { Express, Request, Response, NextFunction } from 'express';
import { MeshTxBuilder, BlockfrostProvider, type UTxO } from '@meshsdk/core';
import { z } from 'zod';
import { bf } from '../lib/blockfrost.js';
import { getTreasuryWalletForKey } from '../lib/policy.js';

const TransferRequest = z.object({
    treasury_env_key: z.string().min(1).max(64),
    recipient_addr:   z.string().min(10),
    unit:             z.string().min(56),
    quantity:         z.union([z.string(), z.number().int().positive()]).optional(),
    submit:           z.boolean().optional().default(true),
});
const PairTransferRequest = z.object({
    treasury_env_key:   z.string().min(1).max(64),
    recipient_addr:     z.string().min(10),
    nft_unit:           z.string().min(56),
    companion_unit:     z.string().min(56),
    companion_quantity: z.union([z.string(), z.number().int().positive()]).optional(),
    submit:             z.boolean().optional().default(true),
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

function normUnit(raw: string): string {
    const t = raw.trim().toLowerCase().replace(/^0x/, '');
    if (!/^[0-9a-f]{56,}$/.test(t)) {
        throw new Error('unit must be hex policy_id + asset_name_hex');
    }
    return t;
}

function normQty(raw: string | number | undefined): string {
    if (raw === undefined || raw === null || raw === '') return '1';
    const n = typeof raw === 'number' ? raw : Number(raw);
    if (!Number.isFinite(n) || n <= 0 || !Number.isInteger(n)) {
        throw new Error('quantity must be a positive integer');
    }
    return String(n);
}
function normCompanionQtyOne(raw: string | number | undefined): string {
    const qty = normQty(raw);
    if (qty !== '1') {
        throw new Error('companion_quantity must be exactly 1');
    }
    return qty;
}

function lovelaceBalance(utxos: UTxO[]): number {
    return utxos.reduce((sum: number, u: UTxO) => {
        const lovelace = u.output.amount.find((a: { unit: string }) => a.unit === 'lovelace');
        return sum + Number(lovelace?.quantity ?? 0);
    }, 0);
}

function unitBalance(utxos: UTxO[], unit: string): bigint {
    return utxos.reduce((sum: bigint, u: UTxO) => {
        const token = u.output.amount.find((a: { unit: string }) => a.unit === unit);
        if (!token) return sum;
        try {
            return sum + BigInt(token.quantity ?? '0');
        } catch {
            return sum;
        }
    }, 0n);
}

export function mountCompanionRoutes(app: Express): void {
    app.get('/companion/treasury/:envKey/balance', async (req: Request, res: Response, next: NextFunction) => {
        try {
            const envKey = String(req.params.envKey ?? '').toUpperCase();
            if (!envKey || !/^[A-Z0-9_]{1,64}$/.test(envKey)) {
                return res.status(400).json({ error: 'invalid env_key' });
            }
            const wallet = getTreasuryWalletForKey(envKey);
            const addr = wallet.getPaymentAddress();
            const utxos = await buildProvider().fetchAddressUTxOs(addr);
            const balance = lovelaceBalance(utxos);
            res.json({
                env_key: envKey,
                treasury_addr: addr,
                balance_lovelace: balance,
                balance_ada: balance / 1_000_000,
            });
        } catch (err) {
            next(err);
        }
    });

    app.get('/companion/treasury/:envKey/unit/:unit/balance', async (req: Request, res: Response, next: NextFunction) => {
        try {
            const envKey = String(req.params.envKey ?? '').toUpperCase();
            if (!envKey || !/^[A-Z0-9_]{1,64}$/.test(envKey)) {
                return res.status(400).json({ error: 'invalid env_key' });
            }
            const unitRaw = String(req.params.unit ?? '').trim().toLowerCase().replace(/^0x/, '');
            if (!/^[0-9a-f]{56,}$/.test(unitRaw)) {
                return res.status(400).json({ error: 'invalid unit' });
            }
            const unit = unitRaw;
            const wallet = getTreasuryWalletForKey(envKey);
            const addr = wallet.getPaymentAddress();
            const utxos = await buildProvider().fetchAddressUTxOs(addr);
            const lovelace = lovelaceBalance(utxos);
            const quantity = unitBalance(utxos, unit);
            res.json({
                env_key: envKey,
                treasury_addr: addr,
                unit,
                quantity: quantity.toString(),
                has_unit: quantity > 0n,
                balance_lovelace: lovelace,
                balance_ada: lovelace / 1_000_000,
            });
        } catch (err) {
            next(err);
        }
    });

    app.post('/companion/transfer', async (req: Request, res: Response, next: NextFunction) => {
        const allowUnpaired = String(process.env.COMPANION_UNPAIRED_TRANSFER_ENABLED ?? '')
            .trim()
            .toLowerCase() === 'true';
        if (!allowUnpaired) {
            return res.status(403).json({
                error: 'unpaired_transfer_disabled',
                message: 'Companion-only transfer is disabled. Use /companion/transfer-paired.',
            });
        }
        const parsed = TransferRequest.safeParse(req.body);
        if (!parsed.success) {
            return res.status(400).json({ error: 'invalid request', issues: parsed.error.issues });
        }

        try {
            const envKey = parsed.data.treasury_env_key.toUpperCase();
            const recipientAddr = normAddr(parsed.data.recipient_addr);
            const unit = normUnit(parsed.data.unit);
            const quantity = normQty(parsed.data.quantity);
            const submit = parsed.data.submit;

            const wallet = getTreasuryWalletForKey(envKey);
            const treasuryAddr = wallet.getPaymentAddress();
            const provider = buildProvider();
            const utxos = await provider.fetchAddressUTxOs(treasuryAddr);
            if (!utxos || utxos.length === 0) {
                return res.status(422).json({
                    error: 'no_utxos',
                    message: 'Treasury wallet has no UTxOs. Fund it with ADA before transfers.',
                });
            }

            const unsignedTx = await new MeshTxBuilder({ fetcher: provider })
                .txOut(recipientAddr, [{ unit, quantity }])
                .changeAddress(treasuryAddr)
                .selectUtxosFrom(utxos)
                .complete();
            const signedTx = await wallet.signTx(unsignedTx);

            if (!submit) {
                return res.json({
                    submitted: false,
                    cbor_hex: signedTx,
                    treasury_env_key: envKey,
                    treasury_addr: treasuryAddr,
                    recipient_addr: recipientAddr,
                    unit,
                    quantity,
                });
            }

            const txHash = await bf().txSubmit(signedTx);
            console.log(
                `[companion/transfer] env=${envKey} unit=${unit.slice(0, 16)}... ` +
                `qty=${quantity} to=${recipientAddr.slice(0, 18)}... tx=${txHash}`,
            );

            res.json({
                submitted: true,
                tx_hash: txHash,
                treasury_env_key: envKey,
                treasury_addr: treasuryAddr,
                recipient_addr: recipientAddr,
                unit,
                quantity,
            });
        } catch (err) {
            next(err);
        }
    });

    app.post('/companion/transfer-paired', async (req: Request, res: Response, next: NextFunction) => {
        const parsed = PairTransferRequest.safeParse(req.body);
        if (!parsed.success) {
            return res.status(400).json({ error: 'invalid request', issues: parsed.error.issues });
        }

        try {
            const envKey = parsed.data.treasury_env_key.toUpperCase();
            const recipientAddr = normAddr(parsed.data.recipient_addr);
            const nftUnit = normUnit(parsed.data.nft_unit);
            const companionUnit = normUnit(parsed.data.companion_unit);
            const companionQuantity = normCompanionQtyOne(parsed.data.companion_quantity);
            const submit = parsed.data.submit;

            const wallet = getTreasuryWalletForKey(envKey);
            const treasuryAddr = wallet.getPaymentAddress();
            const provider = buildProvider();
            const utxos = await provider.fetchAddressUTxOs(treasuryAddr);
            if (!utxos || utxos.length === 0) {
                return res.status(422).json({
                    error: 'no_utxos',
                    message: 'Treasury wallet has no UTxOs. Fund it with ADA before transfers.',
                });
            }

            const unsignedTx = await new MeshTxBuilder({ fetcher: provider })
                .txOut(recipientAddr, [
                    { unit: 'lovelace', quantity: '2000000' },
                    { unit: nftUnit, quantity: '1' },
                    { unit: companionUnit, quantity: companionQuantity },
                ])
                .changeAddress(treasuryAddr)
                .selectUtxosFrom(utxos)
                .complete();
            const signedTx = await wallet.signTx(unsignedTx);

            if (!submit) {
                return res.json({
                    submitted: false,
                    cbor_hex: signedTx,
                    treasury_env_key: envKey,
                    treasury_addr: treasuryAddr,
                    recipient_addr: recipientAddr,
                    nft_unit: nftUnit,
                    nft_quantity: '1',
                    companion_unit: companionUnit,
                    companion_quantity: companionQuantity,
                });
            }

            const txHash = await bf().txSubmit(signedTx);
            console.log(
                `[companion/transfer-paired] env=${envKey} nft=${nftUnit.slice(0, 16)}... ` +
                `companion=${companionUnit.slice(0, 16)}... to=${recipientAddr.slice(0, 18)}... tx=${txHash}`,
            );

            res.json({
                submitted: true,
                tx_hash: txHash,
                treasury_env_key: envKey,
                treasury_addr: treasuryAddr,
                recipient_addr: recipientAddr,
                nft_unit: nftUnit,
                nft_quantity: '1',
                companion_unit: companionUnit,
                companion_quantity: companionQuantity,
            });
        } catch (err) {
            next(err);
        }
    });
}
