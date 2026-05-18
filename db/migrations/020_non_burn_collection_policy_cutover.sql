-- Non-burn collection policy cutover (stage 1).
-- Purpose:
--   1) Snapshot current collection policy fields for rollback.
--   2) Persist new policy_env_key on the target collection.
--   3) Optionally persist derived policy_id and policy_addr.
--
-- Required edits before running:
--   - Set @target_collection_slug
--   - Set @new_policy_env_key
-- Optional:
--   - Set @new_policy_id once derived from sidecar /mint/policy-id?env_key=...
--   - Set @new_policy_addr once derived from sidecar
--
-- This migration is idempotent for the target row.

SET @target_collection_slug := 'silverbar-01-founders-v2';
SET @new_policy_env_key     := 'FOUNDERS_V2';
SET @new_policy_id          := '';
SET @new_policy_addr        := '';

SET @guard_sql := IF(
    @target_collection_slug = 'REPLACE_COLLECTION_SLUG'
    OR @new_policy_env_key = 'REPLACE_POLICY_ENV_KEY',
    'SIGNAL SQLSTATE ''45000'' SET MESSAGE_TEXT = ''Set collection slug and policy env key before running migration 020_non_burn_collection_policy_cutover.sql''',
    'SELECT 1'
);
PREPARE guard_stmt FROM @guard_sql;
EXECUTE guard_stmt;
DEALLOCATE PREPARE guard_stmt;

SET @new_policy_env_key := UPPER(@new_policy_env_key);

CREATE TABLE IF NOT EXISTS qd_collection_policy_cutover_backup (
    id                       BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    migration_name           VARCHAR(191) NOT NULL,
    collection_id            BIGINT UNSIGNED NOT NULL,
    slug                     VARCHAR(64) NOT NULL,
    previous_policy_env_key  VARCHAR(64) NULL,
    previous_policy_id       CHAR(56) NULL,
    previous_policy_addr     VARCHAR(128) NULL,
    captured_at              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_collection_id (collection_id),
    KEY idx_migration_name (migration_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO qd_collection_policy_cutover_backup
    (migration_name, collection_id, slug, previous_policy_env_key, previous_policy_id, previous_policy_addr)
SELECT
    '020_non_burn_collection_policy_cutover.sql',
    c.id,
    c.slug,
    c.policy_env_key,
    c.policy_id,
    c.policy_addr
FROM qd_collections c
WHERE c.slug = (@target_collection_slug COLLATE utf8mb4_unicode_ci);

INSERT INTO qd_collections
    (slug, name, network, policy_env_key, lock_status, edition_size, primary_minted_count, all_primary_minted, created_at, updated_at)
SELECT
    @target_collection_slug,
    @target_collection_slug,
    'mainnet',
    @new_policy_env_key,
    'open',
    0,
    0,
    0,
    NOW(),
    NOW()
WHERE NOT EXISTS (
    SELECT 1 FROM qd_collections c WHERE c.slug = (@target_collection_slug COLLATE utf8mb4_unicode_ci)
);

UPDATE qd_collections
SET
    policy_env_key = @new_policy_env_key,
    policy_id = CASE
        WHEN @new_policy_id REGEXP '^[0-9A-Fa-f]{56}$' THEN LOWER(@new_policy_id)
        ELSE policy_id
    END,
    policy_addr = CASE
        WHEN @new_policy_addr <> '' THEN @new_policy_addr
        ELSE policy_addr
    END,
    updated_at = NOW()
WHERE slug = (@target_collection_slug COLLATE utf8mb4_unicode_ci);

-- Validation gates (run manually after migration):
-- SELECT slug, policy_env_key, policy_id, policy_addr
-- FROM qd_collections
-- WHERE slug = '<target_collection_slug>';
--
-- Rollback posture (manual, no burn):
-- UPDATE qd_collections c
-- JOIN (
--   SELECT b.collection_id, b.previous_policy_env_key, b.previous_policy_id, b.previous_policy_addr
--   FROM qd_collection_policy_cutover_backup b
--   WHERE b.migration_name = '020_non_burn_collection_policy_cutover.sql'
--   ORDER BY b.id DESC
--   LIMIT 1
-- ) r ON r.collection_id = c.id
-- SET c.policy_env_key = r.previous_policy_env_key,
--     c.policy_id = r.previous_policy_id,
--     c.policy_addr = r.previous_policy_addr,
--     c.updated_at = NOW();
