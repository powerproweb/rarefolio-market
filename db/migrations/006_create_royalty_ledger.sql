-- Royalty ledger: one row per secondary sale settlement.
-- Records the 8% creator / 2.5% platform / seller net split.

CREATE TABLE IF NOT EXISTS royalty_ledger (
    id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    nft_id             BIGINT UNSIGNED NOT NULL,          -- FK to qd_tokens.id
    listing_id         BIGINT UNSIGNED NULL,              -- FK to listings.id (future)
    order_id           BIGINT UNSIGNED NULL,              -- FK to orders.id (future)

    sale_amount_ada    DECIMAL(14,6) NOT NULL,
    creator_cut_ada    DECIMAL(14,6) NOT NULL,            -- 8% of sale_amount_ada
    platform_cut_ada   DECIMAL(14,6) NOT NULL,            -- 2.5% of sale_amount_ada
    seller_net_ada     DECIMAL(14,6) NOT NULL,            -- sale - creator - platform

    creator_addr       VARCHAR(128) NOT NULL,
    platform_addr      VARCHAR(128) NOT NULL,
    seller_addr        VARCHAR(128) NOT NULL,
    buyer_addr         VARCHAR(128) NOT NULL,

    tx_hash            CHAR(64)     NOT NULL,
    block_height       BIGINT UNSIGNED NULL,
    paid_at            DATETIME     NOT NULL,

    created_at         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    KEY idx_nft (nft_id),
    KEY idx_tx_hash (tx_hash),
    KEY idx_paid_at (paid_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
