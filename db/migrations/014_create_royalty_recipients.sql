-- Royalty recipients per collection.
-- Defines how the creator royalty pool is split among multiple wallets.
-- pct values for a collection must sum to exactly 100.0000.
--
-- Example for Founders Block 88 with a 8% total creator royalty:
--   Juan Jose  60% of 8% = 4.8% of sale price
--   Partner    25% of 8% = 2.0% of sale price
--   Charity     7.5% of 8% = 0.6% of sale price
--   Reserve     7.5% of 8% = 0.6% of sale price
--
-- The split wallet receives the full 8%, then auto-distributes per these rows.

CREATE TABLE IF NOT EXISTS qd_royalty_recipients (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    collection_id   BIGINT UNSIGNED NOT NULL,           -- FK to qd_collections.id

    label           VARCHAR(128)    NOT NULL,           -- display name (e.g. 'Juan Jose', 'Partner')
    wallet_addr     VARCHAR(128)    NOT NULL,           -- bech32 Cardano address
    pct             DECIMAL(8,4)    NOT NULL,           -- % share of the creator royalty pool (0–100)
    sort_order      INT             NOT NULL DEFAULT 0, -- display order in admin

    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    KEY idx_collection (collection_id),
    KEY idx_sort       (collection_id, sort_order),

    CONSTRAINT fk_recipients_collection
        FOREIGN KEY (collection_id) REFERENCES qd_collections(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- NOTE: Add your actual recipient rows via the admin UI (admin/collection-detail.php)
-- after running migrations. The example below shows the structure only.
-- -----------------------------------------------------------------------------
-- INSERT INTO qd_royalty_recipients (collection_id, label, wallet_addr, pct, sort_order)
-- SELECT id, 'Juan Jose', 'addr1...your_wallet...', 60.0000, 0 FROM qd_collections WHERE slug = 'silverbar-01-founders';
-- INSERT INTO qd_royalty_recipients (collection_id, label, wallet_addr, pct, sort_order)
-- SELECT id, 'Reserve',   'addr1...reserve...',     40.0000, 1 FROM qd_collections WHERE slug = 'silverbar-01-founders';
