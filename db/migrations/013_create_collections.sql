-- Collection registry.
-- One row per NFT collection (one policy ID per row).
-- Links qd_tokens.collection_slug to its policy, split wallet, and royalty config.
--
-- Policy wallet:  POLICY_MNEMONIC_{policy_env_key} in sidecar .env
-- Split wallet:   SPLIT_MNEMONIC_{split_wallet_env_key} in sidecar .env
-- Lock slot:      Set AFTER all primary mints are confirmed. Never before.

CREATE TABLE IF NOT EXISTS qd_collections (
    id                      BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,

    -- Identity
    slug                    VARCHAR(64)     NOT NULL,   -- matches qd_tokens.collection_slug
    name                    VARCHAR(191)    NOT NULL,   -- display name
    description             TEXT            NULL,
    network                 ENUM('preprod','mainnet') NOT NULL DEFAULT 'preprod',

    -- Policy wallet (minting key)
    -- env var read by sidecar: POLICY_MNEMONIC_{UPPER(policy_env_key)}
    -- e.g. policy_env_key='FOUNDERS' → POLICY_MNEMONIC_FOUNDERS
    policy_env_key          VARCHAR(64)     NOT NULL,
    policy_id               CHAR(56)        NULL,       -- derived; set once, never changes
    policy_addr             VARCHAR(128)    NULL,       -- policy wallet address (fund with ADA for fees)

    -- Policy lock (supply cap)
    -- Set lock_slot AFTER all primary mints are confirmed on-chain.
    -- Once the slot passes no further minting is possible under this policy.
    lock_slot               BIGINT UNSIGNED NULL,       -- null = no lock applied yet
    lock_status             ENUM('open','pending_lock','locked') NOT NULL DEFAULT 'open',

    -- Edition tracking
    edition_size            INT UNSIGNED    NOT NULL DEFAULT 0,   -- total tokens in collection
    primary_minted_count    INT UNSIGNED    NOT NULL DEFAULT 0,   -- confirmed on-chain
    all_primary_minted      TINYINT(1)      NOT NULL DEFAULT 0,   -- 1 when minted_count = edition_size

    -- Royalty config
    royalty_total_pct       DECIMAL(6,4)    NOT NULL DEFAULT 8.0000, -- total creator royalty %
    platform_fee_pct        DECIMAL(6,4)    NOT NULL DEFAULT 2.5000, -- platform fee %
    -- CIP-27 on-chain royalty token points to the split wallet address (single recipient on-chain)
    -- Multi-party splits are enforced by RareFolio at settlement time via qd_royalty_recipients

    -- Split wallet (receives sale proceeds, auto-distributes to recipients)
    -- env var read by sidecar: SPLIT_MNEMONIC_{UPPER(split_wallet_env_key)}
    -- Can be same env key as policy_env_key (same wallet) or different.
    split_wallet_env_key    VARCHAR(64)     NULL,
    split_wallet_addr       VARCHAR(128)    NULL,       -- derived from split_wallet_env_key
    split_min_sweep_lovelace BIGINT UNSIGNED NOT NULL DEFAULT 20000000, -- 20 ADA minimum before auto-sweep

    created_at              DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at              DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_slug          (slug),
    UNIQUE KEY uq_policy_env_key (policy_env_key),
    KEY idx_policy_id           (policy_id),
    KEY idx_split_wallet        (split_wallet_addr),
    KEY idx_network             (network),
    KEY idx_lock_status         (lock_status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- Seed: Founders Block 88 (backfills the existing collection)
-- -----------------------------------------------------------------------------
-- Run AFTER migration 007 has seeded qd_tokens.
-- policy_env_key='FOUNDERS' means the sidecar reads POLICY_MNEMONIC_FOUNDERS
-- and SPLIT_MNEMONIC_FOUNDERS from sidecar/.env.
-- Update policy_id and policy_addr after running GET /mint/policy-id?env_key=FOUNDERS.
-- -----------------------------------------------------------------------------
INSERT INTO qd_collections
    (slug, name, description, network,
     policy_env_key, policy_id, policy_addr,
     lock_status, edition_size, primary_minted_count,
     royalty_total_pct, platform_fee_pct,
     split_wallet_env_key, split_wallet_addr, split_min_sweep_lovelace)
VALUES
    ('silverbar-01-founders',
     'Founders Block 88 — Silver Bar I',
     'Eight founder-tier CNFTs anchored to Silver Bar I (Serial E101837). Limited to 8 editions.',
     'preprod',
     'FOUNDERS',
     NULL,   -- update after: curl http://localhost:4000/mint/policy-id?env_key=FOUNDERS
     NULL,   -- update after: policy_addr from the same response
     'open',
     8,
     0,
     8.0000,
     2.5000,
     'FOUNDERS',   -- same wallet for simplicity; set a different key for a dedicated split wallet
     NULL,         -- update after: curl http://localhost:4000/sweep/balance/FOUNDERS
     20000000)
ON DUPLICATE KEY UPDATE
    name        = VALUES(name),
    description = VALUES(description),
    updated_at  = CURRENT_TIMESTAMP;
