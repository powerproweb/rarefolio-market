-- Canonical on-chain token registry.
-- Every RareFolio NFT that has been minted (or imported) lives here.

CREATE TABLE IF NOT EXISTS qd_tokens (
    id                       BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    rarefolio_token_id       VARCHAR(32)      NOT NULL,          -- stable internal id (e.g. RF-0001)
    policy_id                CHAR(56)         NOT NULL,          -- 28-byte hex
    asset_name_hex           VARCHAR(128)     NOT NULL,          -- hex-encoded on-chain asset name
    asset_name_utf8          VARCHAR(128)     NULL,              -- decoded display name
    asset_fingerprint        VARCHAR(64)      NULL,              -- asset1... CIP-14

    collection_slug          VARCHAR(64)      NOT NULL,          -- e.g. 'genesis', 'founders'
    title                    VARCHAR(191)     NOT NULL,
    character_name           VARCHAR(191)     NULL,
    edition                  VARCHAR(32)      NULL,              -- e.g. '3/50'
    artist                   VARCHAR(128)     NULL,

    mint_tx_hash             CHAR(64)         NULL,
    minted_at                DATETIME         NULL,

    current_owner_wallet     VARCHAR(128)     NULL,              -- bech32 addr or stake addr
    current_owner_user_id    BIGINT UNSIGNED  NULL,              -- FK to users once that table exists

    custody_status           ENUM('external','escrow','platform') NOT NULL DEFAULT 'external',
    listing_status           ENUM('none','listed_fixed','listed_auction','offer_only') NOT NULL DEFAULT 'none',
    primary_sale_status      ENUM('unminted','minted','sold','sold_pre_marketplace') NOT NULL DEFAULT 'unminted',
    secondary_eligible       TINYINT(1)       NOT NULL DEFAULT 0,

    metadata_version         VARCHAR(16)      NOT NULL DEFAULT 'cip25-v1',
    cip25_json               JSON             NULL,
    cip68_datum_ref          VARCHAR(128)     NULL,

    created_at               DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at               DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_policy_asset (policy_id, asset_name_hex),
    UNIQUE KEY uq_rarefolio_token_id (rarefolio_token_id),
    KEY idx_collection (collection_slug),
    KEY idx_owner_wallet (current_owner_wallet),
    KEY idx_owner_user (current_owner_user_id),
    KEY idx_listing_status (listing_status),
    KEY idx_primary_status (primary_sale_status),
    KEY idx_fingerprint (asset_fingerprint)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
