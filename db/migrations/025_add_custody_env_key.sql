SET @db_name := DATABASE();
SET @col_exists := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db_name
    AND TABLE_NAME = 'qd_collections'
    AND COLUMN_NAME = 'custody_env_key'
);
SET @ddl := IF(
  @col_exists = 0,
  'ALTER TABLE qd_collections ADD COLUMN custody_env_key VARCHAR(64) NULL AFTER policy_env_key',
  'SELECT 1'
);
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
UPDATE qd_collections
SET custody_env_key = 'V2', updated_at = NOW()
WHERE slug = 'silverbar-01-founders-v2'
  AND (custody_env_key IS NULL OR custody_env_key = '');
