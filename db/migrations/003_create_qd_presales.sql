-- Pre-marketplace sales ledger.
-- Every NFT sold before the secondary marketplace launches is recorded here
-- so it can be backfilled into qd_tokens and claimed by its current owner.

CREATE TABLE IF NOT EXISTS qd_presales (
    id                   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    rarefolio_token_id   VARCHAR(32)     NOT NULL,
    policy_id            CHAR(56)        NOT NULL,
    asset_name_hex       VARCHAR(128)    NOT NULL,
    asset_fingerprint    VARCHAR(64)     NULL,

    character_name       VARCHAR(191)    NULL,
    edition              VARCHAR(32)     NULL,

    buyer_wallet_addr    VARCHAR(128)    NOT NULL,
    buyer_email          VARCHAR(191)    NULL,
    buyer_name           VARCHAR(191)    NULL,

    sale_price_ada       DECIMAL(14,6)   NOT NULL,
    sale_date            DATETIME        NOT NULL,
    mint_tx_hash         CHAR(64)        NULL,
    transfer_tx_hash     CHAR(64)        NULL,

    gift_flag            TINYINT(1)      NOT NULL DEFAULT 0,
    gift_recipient_email VARCHAR(191)    NULL,

    claim_status         ENUM('unclaimed','claimed','reconciled') NOT NULL DEFAULT 'unclaimed',
    claimed_at           DATETIME        NULL,
    claimed_user_id      BIGINT UNSIGNED NULL,

    notes                TEXT            NULL,
    created_at           DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at           DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_policy_asset (policy_id, asset_name_hex),
    KEY idx_buyer_wallet (buyer_wallet_addr),
    KEY idx_buyer_email (buyer_email),
    KEY idx_claim_status (claim_status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
