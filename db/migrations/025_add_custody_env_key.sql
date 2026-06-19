ALTER TABLE qd_collections ADD COLUMN IF NOT EXISTS custody_env_key VARCHAR(64) NULL AFTER policy_env_key;
UPDATE qd_collections SET custody_env_key = 'V2', updated_at = NOW()
 WHERE slug = 'silverbar-01-founders-v2' AND (custody_env_key IS NULL OR custody_env_key = '');
