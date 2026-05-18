-- Non-burn IPFS CID cutover (stage 2).
-- Purpose:
--   1) Snapshot target rows for rollback.
--   2) Replace old CID with new CID in qd_tokens.cip25_json.
--   3) Replace old CID with new CID in qd_mint_queue.cip25_json.
--   4) Update qd_mint_queue.image_ipfs_cid.
--
-- Required edits before running:
--   - Set @target_collection_slug
--   - Set @old_cid
--   - Set @new_cid
--
-- This migration is idempotent for rows that already contain @new_cid.

SET @target_collection_slug := 'REPLACE_COLLECTION_SLUG';
SET @old_cid                := 'REPLACE_OLD_CID';
SET @new_cid                := 'REPLACE_NEW_CID';

SET @guard_sql := IF(
    @target_collection_slug = 'REPLACE_COLLECTION_SLUG'
    OR @old_cid = 'REPLACE_OLD_CID'
    OR @new_cid = 'REPLACE_NEW_CID',
    'SIGNAL SQLSTATE ''45000'' SET MESSAGE_TEXT = ''Set collection slug, old CID, and new CID before running migration 021_non_burn_cid_cutover.sql''',
    'SELECT 1'
);
PREPARE guard_stmt FROM @guard_sql;
EXECUTE guard_stmt;
DEALLOCATE PREPARE guard_stmt;

CREATE TABLE IF NOT EXISTS qd_tokens_cid_cutover_backup (
    id                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    migration_name        VARCHAR(191) NOT NULL,
    token_id              BIGINT UNSIGNED NOT NULL,
    rarefolio_token_id    VARCHAR(32) NOT NULL,
    collection_slug       VARCHAR(64) NOT NULL,
    previous_cip25_json   JSON NULL,
    captured_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_token_id (token_id),
    KEY idx_migration_name (migration_name),
    KEY idx_collection_slug (collection_slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS qd_mint_queue_cid_cutover_backup (
    id                         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    migration_name             VARCHAR(191) NOT NULL,
    mint_queue_id              BIGINT UNSIGNED NOT NULL,
    rarefolio_token_id         VARCHAR(32) NOT NULL,
    collection_slug            VARCHAR(64) NOT NULL,
    previous_cip25_json        JSON NULL,
    previous_image_ipfs_cid    VARCHAR(128) NULL,
    captured_at                DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_mint_queue_id (mint_queue_id),
    KEY idx_migration_name (migration_name),
    KEY idx_collection_slug (collection_slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO qd_tokens_cid_cutover_backup
    (migration_name, token_id, rarefolio_token_id, collection_slug, previous_cip25_json)
SELECT
    '021_non_burn_cid_cutover.sql',
    t.id,
    t.rarefolio_token_id,
    t.collection_slug,
    t.cip25_json
FROM qd_tokens t
WHERE t.collection_slug = @target_collection_slug
  AND t.cip25_json LIKE CONCAT('%', @old_cid, '%');

INSERT INTO qd_mint_queue_cid_cutover_backup
    (migration_name, mint_queue_id, rarefolio_token_id, collection_slug, previous_cip25_json, previous_image_ipfs_cid)
SELECT
    '021_non_burn_cid_cutover.sql',
    q.id,
    q.rarefolio_token_id,
    q.collection_slug,
    q.cip25_json,
    q.image_ipfs_cid
FROM qd_mint_queue q
WHERE q.collection_slug = @target_collection_slug
  AND (
      q.cip25_json LIKE CONCAT('%', @old_cid, '%')
      OR q.image_ipfs_cid = @old_cid
  );

UPDATE qd_tokens
SET
    cip25_json = REPLACE(cip25_json, @old_cid, @new_cid),
    updated_at = NOW()
WHERE collection_slug = @target_collection_slug
  AND cip25_json LIKE CONCAT('%', @old_cid, '%');

UPDATE qd_mint_queue
SET
    cip25_json = REPLACE(cip25_json, @old_cid, @new_cid),
    image_ipfs_cid = CASE
        WHEN image_ipfs_cid = @old_cid THEN @new_cid
        ELSE image_ipfs_cid
    END,
    updated_at = NOW()
WHERE collection_slug = @target_collection_slug
  AND (
      cip25_json LIKE CONCAT('%', @old_cid, '%')
      OR image_ipfs_cid = @old_cid
  );

-- Validation gates (run manually after migration):
-- SELECT rarefolio_token_id
-- FROM qd_tokens
-- WHERE collection_slug = '<target_collection_slug>'
--   AND cip25_json LIKE CONCAT('%', '<old_cid>', '%');
--
-- SELECT rarefolio_token_id
-- FROM qd_mint_queue
-- WHERE collection_slug = '<target_collection_slug>'
--   AND (cip25_json LIKE CONCAT('%', '<old_cid>', '%') OR image_ipfs_cid = '<old_cid>');
--
-- Rollback posture (manual, no burn):
-- UPDATE qd_tokens t
-- JOIN (
--   SELECT b.token_id, b.previous_cip25_json
--   FROM qd_tokens_cid_cutover_backup b
--   WHERE b.migration_name = '021_non_burn_cid_cutover.sql'
-- ) r ON r.token_id = t.id
-- SET t.cip25_json = r.previous_cip25_json,
--     t.updated_at = NOW();
--
-- UPDATE qd_mint_queue q
-- JOIN (
--   SELECT b.mint_queue_id, b.previous_cip25_json, b.previous_image_ipfs_cid
--   FROM qd_mint_queue_cid_cutover_backup b
--   WHERE b.migration_name = '021_non_burn_cid_cutover.sql'
-- ) r ON r.mint_queue_id = q.id
-- SET q.cip25_json = r.previous_cip25_json,
--     q.image_ipfs_cid = r.previous_image_ipfs_cid,
--     q.updated_at = NOW();
