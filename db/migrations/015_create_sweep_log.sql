-- Sweep log: records every automatic and manual distribution run.
-- One row per sweep attempt on a split wallet.
-- distributions JSON captures exact amounts sent to each recipient.

CREATE TABLE IF NOT EXISTS qd_sweep_log (
    id                      BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    collection_id           BIGINT UNSIGNED NOT NULL,           -- FK to qd_collections.id

    trigger_type            ENUM('blockfrost_webhook','manual','cron') NOT NULL DEFAULT 'manual',

    -- Amounts
    sweep_amount_lovelace   BIGINT UNSIGNED NOT NULL,           -- total ADA swept (pre-fee)
    fee_lovelace            BIGINT UNSIGNED NULL,               -- estimated tx fee

    -- Distributions snapshot (JSON array)
    -- [{ "addr": "addr1...", "label": "Juan", "lovelace": 1000000, "pct": 60.0 }, ...]
    distributions           JSON            NOT NULL,

    -- On-chain
    tx_hash                 CHAR(64)        NULL,

    -- Lifecycle
    status                  ENUM('pending','submitted','confirmed','failed') NOT NULL DEFAULT 'pending',
    error_msg               TEXT            NULL,

    created_at              DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at              DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    KEY idx_collection  (collection_id),
    KEY idx_status      (status),
    KEY idx_tx_hash     (tx_hash),
    KEY idx_trigger     (trigger_type),
    KEY idx_created_at  (created_at),

    CONSTRAINT fk_sweep_collection
        FOREIGN KEY (collection_id) REFERENCES qd_collections(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
