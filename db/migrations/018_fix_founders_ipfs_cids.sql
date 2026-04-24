-- Phase E.3 fix: replace REPLACE_WITH_CID placeholder in cip25_json using
-- MySQL REPLACE() string function. This works regardless of JSON nesting depth
-- and is idempotent (no-op if placeholder is already gone).
--
-- Migration 017 used JSON_SET('$.image') which targeted the wrong path —
-- the image field is nested under policy_id and asset_name in the wrapped
-- CIP-25 format. REPLACE() on the raw JSON string is the reliable alternative.
--
-- IPFS folder CID: bafybeigcsosusr5dvsgfkn4ox3sgqyr3gzmd4cal32guxijygxzpd5x6vy

UPDATE qd_tokens
SET
    cip25_json = REPLACE(
        cip25_json,
        'REPLACE_WITH_CID',
        'bafybeigcsosusr5dvsgfkn4ox3sgqyr3gzmd4cal32guxijygxzpd5x6vy'
    ),
    updated_at = NOW()
WHERE
    collection_slug = 'silverbar-01-founders'
    AND cip25_json LIKE '%REPLACE_WITH_CID%';

-- Also fix qd_mint_queue rows (preprod history — keeps records consistent)
UPDATE qd_mint_queue
SET
    cip25_json    = REPLACE(
        cip25_json,
        'REPLACE_WITH_CID',
        'bafybeigcsosusr5dvsgfkn4ox3sgqyr3gzmd4cal32guxijygxzpd5x6vy'
    ),
    image_ipfs_cid = 'bafybeigcsosusr5dvsgfkn4ox3sgqyr3gzmd4cal32guxijygxzpd5x6vy',
    updated_at    = NOW()
WHERE
    collection_slug = 'silverbar-01-founders'
    AND cip25_json LIKE '%REPLACE_WITH_CID%';
