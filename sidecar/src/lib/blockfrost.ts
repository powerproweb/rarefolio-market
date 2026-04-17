import { BlockFrostAPI } from '@blockfrost/blockfrost-js';

let instance: BlockFrostAPI | null = null;

export function bf(): BlockFrostAPI {
    if (instance) return instance;
    const projectId = process.env.BLOCKFROST_API_KEY;
    if (!projectId) {
        throw new Error('BLOCKFROST_API_KEY is not set');
    }
    const network = (process.env.BLOCKFROST_NETWORK ?? 'preprod') as
        'mainnet' | 'preprod' | 'preview';
    instance = new BlockFrostAPI({ projectId, network });
    return instance;
}
