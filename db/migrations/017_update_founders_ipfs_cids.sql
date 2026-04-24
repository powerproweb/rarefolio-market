-- Phase E.3: Replace placeholder IPFS CIDs in qd_tokens with real pinned URIs.
--
-- IPFS folder CID: bafybeigcsosusr5dvsgfkn4ox3sgqyr3gzmd4cal32guxijygxzpd5x6vy
-- Pinned on: 2026-04-24 via Pinata (free tier)
-- Gateway verified: https://gateway.pinata.cloud/ipfs/bafybeig.../qd-silver-0000705.jpg → 200 OK
--
-- Updates both:
--   1. qd_tokens.cip25_json  — the stored CIP-25 metadata (image field)
--   2. qd_tokens.image_ipfs_cid — the extracted CID column (used by admin UI)
--
-- Safe to re-run: JSON_SET is idempotent.
-- Run: php db/migrate.php  (migration runner applies in numeric order)

UPDATE qd_tokens
SET
    cip25_json = JSON_SET(
        cip25_json,
        '$.image',
        CONCAT(
            'ipfs://bafybeigcsosusr5dvsgfkn4ox3sgqyr3gzmd4cal32guxijygxzpd5x6vy/',
            rarefolio_token_id,
            '.jpg'
        )
    ),
    image_ipfs_cid = 'bafybeigcsosusr5dvsgfkn4ox3sgqyr3gzmd4cal32guxijygxzpd5x6vy',
    updated_at     = NOW()
WHERE
    collection_slug = 'silverbar-01-founders'
    AND rarefolio_token_id LIKE 'qd-silver-%';

-- Verify (run manually after migration):
-- SELECT rarefolio_token_id, JSON_UNQUOTE(JSON_EXTRACT(cip25_json, '$.image')) AS image
-- FROM qd_tokens WHERE collection_slug = 'silverbar-01-founders';
