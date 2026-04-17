-- Mint queue workflow for the admin dashboard.
-- draft -> ready -> signed -> submitted -> confirmed -> failed

CREATE TABLE IF NOT EXISTS qd_mint_queue (
    id                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    rarefolio_token_id    VARCHAR(32)  NOT NULL,
    collection_slug       VARCHAR(64)  NOT NULL,
    policy_id             CHAR(56)     NULL,               -- null until policy exists
    asset_name_hex        VARCHAR(128) NOT NULL,
    title                 VARCHAR(191) NOT NULL,
    character_name        VARCHAR(191) NULL,
    edition               VARCHAR(32)  NULL,
    cip25_json            JSON         NOT NULL,
    image_ipfs_cid        VARCHAR(128) NULL,
    image_pin_providers   VARCHAR(255) NULL,                -- comma-separated (pinata,nft.storage)
    royalty_token_ok      TINYINT(1)   NOT NULL DEFAULT 0,  -- CIP-27 royalty token locked on policy?

    status                ENUM('draft','ready','signed','submitted','confirmed','failed')
                              NOT NULL DEFAULT 'draft',
    tx_hash               CHAR(64)     NULL,
    error_message         TEXT         NULL,
    attempts              INT UNSIGNED NOT NULL DEFAULT 0,

    created_by_admin      VARCHAR(64)  NULL,
    created_at            DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at            DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    submitted_at          DATETIME     NULL,
    confirmed_at          DATETIME     NULL,

    PRIMARY KEY (id),
    UNIQUE KEY uq_rarefolio_token_id (rarefolio_token_id),
    KEY idx_status (status),
    KEY idx_collection (collection_slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
