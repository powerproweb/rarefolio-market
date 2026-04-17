-- ADA Handle resolution cache (handle -> address, with TTL).

CREATE TABLE IF NOT EXISTS ada_handles (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    handle          VARCHAR(128) NOT NULL,         -- normalized (lowercase, no leading $)
    resolved_addr   VARCHAR(128) NULL,             -- bech32 payment address
    resolved_stake  VARCHAR(128) NULL,             -- stake address
    last_checked_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at      DATETIME     NULL,
    created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_handle (handle),
    KEY idx_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
