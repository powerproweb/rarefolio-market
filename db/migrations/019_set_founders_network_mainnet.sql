-- Phase F mainnet cutover: ensure Founders collection declares mainnet.
-- Idempotent and safe to rerun.
UPDATE qd_collections
SET
    network = 'mainnet',
    updated_at = NOW()
WHERE
    slug = 'silverbar-01-founders'
    AND network <> 'mainnet';
