-- Gifts table: supports the Gift Certificate PDF + redemption flow.

CREATE TABLE IF NOT EXISTS gifts (
    id                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    buyer_user_id         BIGINT UNSIGNED NULL,
    buyer_email           VARCHAR(191) NULL,
    buyer_wallet_addr     VARCHAR(128) NULL,

    nft_id                BIGINT UNSIGNED NULL,     -- FK to qd_tokens.id once minted
    policy_id             CHAR(56)     NULL,
    asset_name_hex        VARCHAR(128) NULL,

    recipient_email       VARCHAR(191) NULL,
    recipient_handle      VARCHAR(128) NULL,        -- ADA Handle ($name)
    recipient_wallet_addr VARCHAR(128) NULL,        -- resolved or provided
    recipient_name        VARCHAR(191) NULL,

    redemption_code       CHAR(32)     NOT NULL,
    certificate_pdf_url   VARCHAR(512) NULL,

    status                ENUM('pending','sent','claimed','expired','canceled')
                              NOT NULL DEFAULT 'pending',
    claim_tx_hash         CHAR(64)     NULL,
    expires_at            DATETIME     NULL,
    sent_at               DATETIME     NULL,
    claimed_at            DATETIME     NULL,

    created_at            DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at            DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_redemption_code (redemption_code),
    KEY idx_buyer_user (buyer_user_id),
    KEY idx_recipient_email (recipient_email),
    KEY idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
