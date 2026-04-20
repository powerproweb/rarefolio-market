-- Primary sale price per collection.
-- NULL   = price on request (contact form)
-- 0      = not for sale / reserved
-- N > 0  = fixed price in lovelace (1 ADA = 1,000,000 lovelace)
--
-- Per-token overrides can be set in qd_listings (migration 010).
-- The buy.php page checks qd_listings first, falls back to this value.

ALTER TABLE qd_collections
    ADD COLUMN primary_sale_price_lovelace BIGINT UNSIGNED NULL
        COMMENT 'Fixed price per token in lovelace. NULL=on request, 0=not for sale.'
    AFTER split_min_sweep_lovelace;
