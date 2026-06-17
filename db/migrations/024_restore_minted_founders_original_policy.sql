-- 024_restore_minted_founders_original_policy.sql  (DRAFT — review before applying)
--
-- Corrective migration. Migration 022 relabeled ALL founders-v2 tokens' policy_id to the
-- FOUNDERS_V2 policy, but the 8 ALREADY-MINTED Founders NFTs are immutably on the ORIGINAL
-- policy on-chain. You cannot relabel a minted asset by changing a DB row — so the DB now
-- points at a V2 policy that has zero holders (this caused the audit's "minted but no holder").
--
-- On-chain truth (Blockfrost, 2026-06-17): qd-silver-0000705..0000712 are each held with
-- quantity 1 under the ORIGINAL policy e29ba98c... in wallet addr1qxmq...y6kcyx; the V2 units
-- have NO holders. This migration restores the DB to match the chain.
--
-- V2 remains correct for FUTURE (unminted) Founders mints — this only touches the 8 minted ones.
-- Idempotent: each statement only updates rows still holding the wrong value.
--
-- BEFORE APPLYING: back up qd_tokens. Run the SELECT at the bottom before and after.

SET collation_connection = 'utf8mb4_unicode_ci';
SET @legacy := 'e29ba98c7633bb62e8d8d0f5013a4a2dee3d1872e00032a0f524c9e9';
SET @v2     := '82ae9440500e297e49144a13832861de3e84e526eee0eb70f4d48af7';
SET @slug   := 'silverbar-01-founders-v2';
SET @owner  := 'addr1qxmq2vw2gmgd6hx360aus8zs9clgv06rvjsufqspupt8j2kha6v8v2623gm620zrd7gpwum4qn423y6x4dwxzvpwvfzqy6kcyx';

-- 1) Restore the real on-chain policy_id on the 8 minted Founders.
UPDATE qd_tokens
SET policy_id = @legacy, updated_at = NOW()
WHERE collection_slug = @slug
  AND mint_tx_hash IS NOT NULL AND mint_tx_hash <> ''
  AND rarefolio_token_id IN (
      'qd-silver-0000705','qd-silver-0000706','qd-silver-0000707','qd-silver-0000708',
      'qd-silver-0000709','qd-silver-0000710','qd-silver-0000711','qd-silver-0000712')
  AND LOWER(policy_id) = @v2;

-- 2) Restore the CIP-25 v721 policy map key (reverse of migration 022's REPLACE).
UPDATE qd_tokens
SET cip25_json = REPLACE(cip25_json, CONCAT('"', @v2, '":'), CONCAT('"', @legacy, '":')),
    updated_at = NOW()
WHERE collection_slug = @slug
  AND mint_tx_hash IS NOT NULL AND mint_tx_hash <> ''
  AND rarefolio_token_id IN (
      'qd-silver-0000705','qd-silver-0000706','qd-silver-0000707','qd-silver-0000708',
      'qd-silver-0000709','qd-silver-0000710','qd-silver-0000711','qd-silver-0000712')
  AND cip25_json LIKE CONCAT('%"', @v2, '":%');

-- 3) Correct current_owner_wallet to the actual on-chain holder (fixes 708's stale value;
--    no-op for the 7 already correct). Chain shows all 8 in the custody wallet, qty 1 each.
UPDATE qd_tokens
SET current_owner_wallet = @owner, updated_at = NOW()
WHERE collection_slug = @slug
  AND rarefolio_token_id IN (
      'qd-silver-0000705','qd-silver-0000706','qd-silver-0000707','qd-silver-0000708',
      'qd-silver-0000709','qd-silver-0000710','qd-silver-0000711','qd-silver-0000712')
  AND COALESCE(current_owner_wallet,'') <> @owner;

-- Verify (run before and after; after-state expectation in comments):
-- SELECT rarefolio_token_id, policy_id, current_owner_wallet, custody_status, primary_sale_status
--   FROM qd_tokens
--  WHERE rarefolio_token_id IN ('qd-silver-0000705','qd-silver-0000706','qd-silver-0000707',
--        'qd-silver-0000708','qd-silver-0000709','qd-silver-0000710','qd-silver-0000711','qd-silver-0000712')
--  ORDER BY rarefolio_token_id;
-- EXPECT after: policy_id = e29ba98c...  for all 8; current_owner_wallet = addr1qxmq...y6kcyx for all 8;
--               cip25_json no longer contains the V2 policy key.

-- NOTE (separate judgement calls — NOT changed here; confirm then adjust if needed):
--   * custody_status: chain shows all 8 in your custody wallet → likely should be 'platform'
--     (currently may be 'external'). Confirm intended pre-sale value before changing.
--   * primary_sale_status: if any of the 8 are flagged 'sold' but are actually staged-unsold
--     in custody, that is also stale. Confirm and correct separately.
