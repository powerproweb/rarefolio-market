-- Align Founders V2 policy references after non-burn cutover.
--
-- Purpose:
--   1) Ensure qd_tokens / qd_mint_queue policy_id values follow
--      qd_collections.policy_id for silverbar-01-founders-v2.
--   2) Rewrite legacy CIP-25 v721 policy map keys from the original Founders
--      policy id to the active FOUNDERS_V2 policy id.
--
-- This migration is idempotent.

SET @target_collection_slug := 'silverbar-01-founders-v2';
SET @legacy_founders_policy_id := 'e29ba98c7633bb62e8d8d0f5013a4a2dee3d1872e00032a0f524c9e9';

UPDATE qd_tokens t
JOIN qd_collections c ON c.slug = t.collection_slug
SET
    t.policy_id = LOWER(c.policy_id),
    t.updated_at = NOW()
WHERE c.slug = @target_collection_slug
  AND c.policy_env_key = 'FOUNDERS_V2'
  AND LOWER(COALESCE(c.policy_id, '')) REGEXP '^[0-9a-f]{56}$'
  AND COALESCE(t.policy_id, '') <> LOWER(c.policy_id);

UPDATE qd_mint_queue q
JOIN qd_collections c ON c.slug = q.collection_slug
SET
    q.policy_id = LOWER(c.policy_id),
    q.updated_at = NOW()
WHERE c.slug = @target_collection_slug
  AND c.policy_env_key = 'FOUNDERS_V2'
  AND LOWER(COALESCE(c.policy_id, '')) REGEXP '^[0-9a-f]{56}$'
  AND COALESCE(q.policy_id, '') <> LOWER(c.policy_id);

UPDATE qd_tokens t
JOIN qd_collections c ON c.slug = t.collection_slug
SET
    t.cip25_json = REPLACE(
        t.cip25_json,
        CONCAT('"', @legacy_founders_policy_id, '":'),
        CONCAT('"', LOWER(c.policy_id), '":')
    ),
    t.updated_at = NOW()
WHERE c.slug = @target_collection_slug
  AND c.policy_env_key = 'FOUNDERS_V2'
  AND LOWER(COALESCE(c.policy_id, '')) REGEXP '^[0-9a-f]{56}$'
  AND LOWER(c.policy_id) <> @legacy_founders_policy_id
  AND t.cip25_json LIKE CONCAT('%"', @legacy_founders_policy_id, '":%');

UPDATE qd_mint_queue q
JOIN qd_collections c ON c.slug = q.collection_slug
SET
    q.cip25_json = REPLACE(
        q.cip25_json,
        CONCAT('"', @legacy_founders_policy_id, '":'),
        CONCAT('"', LOWER(c.policy_id), '":')
    ),
    q.updated_at = NOW()
WHERE c.slug = @target_collection_slug
  AND c.policy_env_key = 'FOUNDERS_V2'
  AND LOWER(COALESCE(c.policy_id, '')) REGEXP '^[0-9a-f]{56}$'
  AND LOWER(c.policy_id) <> @legacy_founders_policy_id
  AND q.cip25_json LIKE CONCAT('%"', @legacy_founders_policy_id, '":%');

-- Validation (manual):
-- SELECT COUNT(*) FROM qd_tokens
-- WHERE collection_slug='silverbar-01-founders-v2'
--   AND policy_id <> (SELECT policy_id FROM qd_collections WHERE slug='silverbar-01-founders-v2' LIMIT 1);
--
-- SELECT COUNT(*) FROM qd_mint_queue
-- WHERE collection_slug='silverbar-01-founders-v2'
--   AND policy_id <> (SELECT policy_id FROM qd_collections WHERE slug='silverbar-01-founders-v2' LIMIT 1);
--
-- SELECT COUNT(*) FROM qd_tokens
-- WHERE collection_slug='silverbar-01-founders-v2'
--   AND cip25_json LIKE '%"e29ba98c7633bb62e8d8d0f5013a4a2dee3d1872e00032a0f524c9e9":%';
--
-- SELECT COUNT(*) FROM qd_mint_queue
-- WHERE collection_slug='silverbar-01-founders-v2'
--   AND cip25_json LIKE '%"e29ba98c7633bb62e8d8d0f5013a4a2dee3d1872e00032a0f524c9e9":%';
