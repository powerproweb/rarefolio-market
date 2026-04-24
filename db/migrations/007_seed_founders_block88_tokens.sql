-- =============================================================================
--  Rarefolio Marketplace — Founders Block 88 seed
-- =============================================================================
--  Seeds the 8 CNFTs that make up the Founders collection on Silver Bar I.
--
--  State on seed: primary_sale_status='unminted', custody_status='platform',
--  listing_status='none'. The mint-action workflow will flip these as the
--  chain work progresses.
--
--  Re-run safe: uses INSERT ... ON DUPLICATE KEY UPDATE on the unique
--  rarefolio_token_id so values can be refined without losing rows.
--
--  Depends on: 001_create_qd_tokens.sql
-- =============================================================================

-- ---- Founders #1 — The Archivist — Keeper of the First Ledger ----------------
INSERT INTO qd_tokens
    (rarefolio_token_id, policy_id, asset_name_hex, asset_name_utf8, asset_fingerprint,
     collection_slug, title, character_name, edition, artist,
     custody_status, listing_status, primary_sale_status, secondary_eligible,
     metadata_version, cip25_json)
VALUES
    ('qd-silver-0000705',
     '00000000000000000000000000000000000000000000000000000000',
     '71642d73696c7665722d30303030373035',
     'qd-silver-0000705',
     NULL,
     'silverbar-01-founders',
     'Founders #1',
     'The Archivist — Keeper of the First Ledger',
     '1/8',
     'Rarefolio',
     'platform', 'none', 'unminted', 1,
     'cip25-v1',
     JSON_OBJECT(
         'name',            'Founders #1 — The Archivist',
         'image',           'ipfs://bafybeigcsosusr5dvsgfkn4ox3sgqyr3gzmd4cal32guxijygxzpd5x6vy/qd-silver-0000705.jpg',
         'mediaType',       'image/jpeg',
         'description',     'Keeper of the First Ledger. Member of the Rarefolio Founders collection anchored to Silver Bar I (Serial E101837).',
         'bar_serial',      'E101837',
         'rarefolio_token_id', 'qd-silver-0000705',
         'collection',      'silverbar-01-founders',
         'edition',         '1/8',
         'attributes',      JSON_OBJECT(
             'bar_serial',  'E101837',
             'block',       '88',
             'archetype',   'Archivist'
         )
     )
    )
ON DUPLICATE KEY UPDATE
    collection_slug = VALUES(collection_slug),
    title           = VALUES(title),
    character_name  = VALUES(character_name),
    edition         = VALUES(edition),
    artist          = VALUES(artist),
    cip25_json      = VALUES(cip25_json),
    updated_at      = CURRENT_TIMESTAMP;

-- ---- Founders #2 — The Cartographer — Drafter of the Vault Map ---------------
INSERT INTO qd_tokens
    (rarefolio_token_id, policy_id, asset_name_hex, asset_name_utf8,
     collection_slug, title, character_name, edition, artist,
     custody_status, listing_status, primary_sale_status, secondary_eligible,
     metadata_version, cip25_json)
VALUES
    ('qd-silver-0000706',
     '00000000000000000000000000000000000000000000000000000000',
     '71642d73696c7665722d30303030373036',
     'qd-silver-0000706',
     'silverbar-01-founders',
     'Founders #2',
     'The Cartographer — Drafter of the Vault Map',
     '2/8',
     'Rarefolio',
     'platform', 'none', 'unminted', 1,
     'cip25-v1',
     JSON_OBJECT(
         'name',            'Founders #2 — The Cartographer',
         'image',           'ipfs://bafybeigcsosusr5dvsgfkn4ox3sgqyr3gzmd4cal32guxijygxzpd5x6vy/qd-silver-0000706.jpg',
         'mediaType',       'image/jpeg',
         'description',     'Drafter of the Vault Map. Member of the Rarefolio Founders collection anchored to Silver Bar I (Serial E101837).',
         'bar_serial',      'E101837',
         'rarefolio_token_id', 'qd-silver-0000706',
         'collection',      'silverbar-01-founders',
         'edition',         '2/8',
         'attributes',      JSON_OBJECT(
             'bar_serial',  'E101837',
             'block',       '88',
             'archetype',   'Cartographer'
         )
     )
    )
ON DUPLICATE KEY UPDATE
    collection_slug = VALUES(collection_slug),
    title           = VALUES(title),
    character_name  = VALUES(character_name),
    edition         = VALUES(edition),
    artist          = VALUES(artist),
    cip25_json      = VALUES(cip25_json),
    updated_at      = CURRENT_TIMESTAMP;

-- ---- Founders #3 — The Sentinel — Warden of the Inaugural Seal ---------------
INSERT INTO qd_tokens
    (rarefolio_token_id, policy_id, asset_name_hex, asset_name_utf8,
     collection_slug, title, character_name, edition, artist,
     custody_status, listing_status, primary_sale_status, secondary_eligible,
     metadata_version, cip25_json)
VALUES
    ('qd-silver-0000707',
     '00000000000000000000000000000000000000000000000000000000',
     '71642d73696c7665722d30303030373037',
     'qd-silver-0000707',
     'silverbar-01-founders',
     'Founders #3',
     'The Sentinel — Warden of the Inaugural Seal',
     '3/8',
     'Rarefolio',
     'platform', 'none', 'unminted', 1,
     'cip25-v1',
     JSON_OBJECT(
         'name',            'Founders #3 — The Sentinel',
         'image',           'ipfs://bafybeigcsosusr5dvsgfkn4ox3sgqyr3gzmd4cal32guxijygxzpd5x6vy/qd-silver-0000707.jpg',
         'mediaType',       'image/jpeg',
         'description',     'Warden of the Inaugural Seal. Member of the Rarefolio Founders collection anchored to Silver Bar I (Serial E101837).',
         'bar_serial',      'E101837',
         'rarefolio_token_id', 'qd-silver-0000707',
         'collection',      'silverbar-01-founders',
         'edition',         '3/8',
         'attributes',      JSON_OBJECT(
             'bar_serial',  'E101837',
             'block',       '88',
             'archetype',   'Sentinel'
         )
     )
    )
ON DUPLICATE KEY UPDATE
    collection_slug = VALUES(collection_slug),
    title           = VALUES(title),
    character_name  = VALUES(character_name),
    edition         = VALUES(edition),
    artist          = VALUES(artist),
    cip25_json      = VALUES(cip25_json),
    updated_at      = CURRENT_TIMESTAMP;

-- ---- Founders #4 — The Artisan — Forger of the Foundational Die --------------
INSERT INTO qd_tokens
    (rarefolio_token_id, policy_id, asset_name_hex, asset_name_utf8,
     collection_slug, title, character_name, edition, artist,
     custody_status, listing_status, primary_sale_status, secondary_eligible,
     metadata_version, cip25_json)
VALUES
    ('qd-silver-0000708',
     '00000000000000000000000000000000000000000000000000000000',
     '71642d73696c7665722d30303030373038',
     'qd-silver-0000708',
     'silverbar-01-founders',
     'Founders #4',
     'The Artisan — Forger of the Foundational Die',
     '4/8',
     'Rarefolio',
     'platform', 'none', 'unminted', 1,
     'cip25-v1',
     JSON_OBJECT(
         'name',            'Founders #4 — The Artisan',
         'image',           'ipfs://bafybeigcsosusr5dvsgfkn4ox3sgqyr3gzmd4cal32guxijygxzpd5x6vy/qd-silver-0000708.jpg',
         'mediaType',       'image/jpeg',
         'description',     'Forger of the Foundational Die. Member of the Rarefolio Founders collection anchored to Silver Bar I (Serial E101837).',
         'bar_serial',      'E101837',
         'rarefolio_token_id', 'qd-silver-0000708',
         'collection',      'silverbar-01-founders',
         'edition',         '4/8',
         'attributes',      JSON_OBJECT(
             'bar_serial',  'E101837',
             'block',       '88',
             'archetype',   'Artisan'
         )
     )
    )
ON DUPLICATE KEY UPDATE
    collection_slug = VALUES(collection_slug),
    title           = VALUES(title),
    character_name  = VALUES(character_name),
    edition         = VALUES(edition),
    artist          = VALUES(artist),
    cip25_json      = VALUES(cip25_json),
    updated_at      = CURRENT_TIMESTAMP;

-- ---- Founders #5 — The Scholar — Historian of the First Provenance -----------
INSERT INTO qd_tokens
    (rarefolio_token_id, policy_id, asset_name_hex, asset_name_utf8,
     collection_slug, title, character_name, edition, artist,
     custody_status, listing_status, primary_sale_status, secondary_eligible,
     metadata_version, cip25_json)
VALUES
    ('qd-silver-0000709',
     '00000000000000000000000000000000000000000000000000000000',
     '71642d73696c7665722d30303030373039',
     'qd-silver-0000709',
     'silverbar-01-founders',
     'Founders #5',
     'The Scholar — Historian of the First Provenance',
     '5/8',
     'Rarefolio',
     'platform', 'none', 'unminted', 1,
     'cip25-v1',
     JSON_OBJECT(
         'name',            'Founders #5 — The Scholar',
         'image',           'ipfs://bafybeigcsosusr5dvsgfkn4ox3sgqyr3gzmd4cal32guxijygxzpd5x6vy/qd-silver-0000709.jpg',
         'mediaType',       'image/jpeg',
         'description',     'Historian of the First Provenance. Member of the Rarefolio Founders collection anchored to Silver Bar I (Serial E101837).',
         'bar_serial',      'E101837',
         'rarefolio_token_id', 'qd-silver-0000709',
         'collection',      'silverbar-01-founders',
         'edition',         '5/8',
         'attributes',      JSON_OBJECT(
             'bar_serial',  'E101837',
             'block',       '88',
             'archetype',   'Scholar'
         )
     )
    )
ON DUPLICATE KEY UPDATE
    collection_slug = VALUES(collection_slug),
    title           = VALUES(title),
    character_name  = VALUES(character_name),
    edition         = VALUES(edition),
    artist          = VALUES(artist),
    cip25_json      = VALUES(cip25_json),
    updated_at      = CURRENT_TIMESTAMP;

-- ---- Founders #6 — The Ambassador — Emissary of the Original Charter ---------
INSERT INTO qd_tokens
    (rarefolio_token_id, policy_id, asset_name_hex, asset_name_utf8,
     collection_slug, title, character_name, edition, artist,
     custody_status, listing_status, primary_sale_status, secondary_eligible,
     metadata_version, cip25_json)
VALUES
    ('qd-silver-0000710',
     '00000000000000000000000000000000000000000000000000000000',
     '71642d73696c7665722d30303030373130',
     'qd-silver-0000710',
     'silverbar-01-founders',
     'Founders #6',
     'The Ambassador — Emissary of the Original Charter',
     '6/8',
     'Rarefolio',
     'platform', 'none', 'unminted', 1,
     'cip25-v1',
     JSON_OBJECT(
         'name',            'Founders #6 — The Ambassador',
         'image',           'ipfs://bafybeigcsosusr5dvsgfkn4ox3sgqyr3gzmd4cal32guxijygxzpd5x6vy/qd-silver-0000710.jpg',
         'mediaType',       'image/jpeg',
         'description',     'Emissary of the Original Charter. Member of the Rarefolio Founders collection anchored to Silver Bar I (Serial E101837).',
         'bar_serial',      'E101837',
         'rarefolio_token_id', 'qd-silver-0000710',
         'collection',      'silverbar-01-founders',
         'edition',         '6/8',
         'attributes',      JSON_OBJECT(
             'bar_serial',  'E101837',
             'block',       '88',
             'archetype',   'Ambassador'
         )
     )
    )
ON DUPLICATE KEY UPDATE
    collection_slug = VALUES(collection_slug),
    title           = VALUES(title),
    character_name  = VALUES(character_name),
    edition         = VALUES(edition),
    artist          = VALUES(artist),
    cip25_json      = VALUES(cip25_json),
    updated_at      = CURRENT_TIMESTAMP;

-- ---- Founders #7 — The Mentor — Steward of the Collector's Path --------------
INSERT INTO qd_tokens
    (rarefolio_token_id, policy_id, asset_name_hex, asset_name_utf8,
     collection_slug, title, character_name, edition, artist,
     custody_status, listing_status, primary_sale_status, secondary_eligible,
     metadata_version, cip25_json)
VALUES
    ('qd-silver-0000711',
     '00000000000000000000000000000000000000000000000000000000',
     '71642d73696c7665722d30303030373131',
     'qd-silver-0000711',
     'silverbar-01-founders',
     'Founders #7',
     'The Mentor — Steward of the Collector''s Path',
     '7/8',
     'Rarefolio',
     'platform', 'none', 'unminted', 1,
     'cip25-v1',
     JSON_OBJECT(
         'name',            'Founders #7 — The Mentor',
         'image',           'ipfs://bafybeigcsosusr5dvsgfkn4ox3sgqyr3gzmd4cal32guxijygxzpd5x6vy/qd-silver-0000711.jpg',
         'mediaType',       'image/jpeg',
         'description',     'Steward of the Collector''s Path. Member of the Rarefolio Founders collection anchored to Silver Bar I (Serial E101837).',
         'bar_serial',      'E101837',
         'rarefolio_token_id', 'qd-silver-0000711',
         'collection',      'silverbar-01-founders',
         'edition',         '7/8',
         'attributes',      JSON_OBJECT(
             'bar_serial',  'E101837',
             'block',       '88',
             'archetype',   'Mentor'
         )
     )
    )
ON DUPLICATE KEY UPDATE
    collection_slug = VALUES(collection_slug),
    title           = VALUES(title),
    character_name  = VALUES(character_name),
    edition         = VALUES(edition),
    artist          = VALUES(artist),
    cip25_json      = VALUES(cip25_json),
    updated_at      = CURRENT_TIMESTAMP;

-- ---- Founders #8 — The Architect — Builder of the Permanent Vault ------------
INSERT INTO qd_tokens
    (rarefolio_token_id, policy_id, asset_name_hex, asset_name_utf8,
     collection_slug, title, character_name, edition, artist,
     custody_status, listing_status, primary_sale_status, secondary_eligible,
     metadata_version, cip25_json)
VALUES
    ('qd-silver-0000712',
     '00000000000000000000000000000000000000000000000000000000',
     '71642d73696c7665722d30303030373132',
     'qd-silver-0000712',
     'silverbar-01-founders',
     'Founders #8',
     'The Architect — Builder of the Permanent Vault',
     '8/8',
     'Rarefolio',
     'platform', 'none', 'unminted', 1,
     'cip25-v1',
     JSON_OBJECT(
         'name',            'Founders #8 — The Architect',
         'image',           'ipfs://bafybeigcsosusr5dvsgfkn4ox3sgqyr3gzmd4cal32guxijygxzpd5x6vy/qd-silver-0000712.jpg',
         'mediaType',       'image/jpeg',
         'description',     'Builder of the Permanent Vault. Member of the Rarefolio Founders collection anchored to Silver Bar I (Serial E101837).',
         'bar_serial',      'E101837',
         'rarefolio_token_id', 'qd-silver-0000712',
         'collection',      'silverbar-01-founders',
         'edition',         '8/8',
         'attributes',      JSON_OBJECT(
             'bar_serial',  'E101837',
             'block',       '88',
             'archetype',   'Architect'
         )
     )
    )
ON DUPLICATE KEY UPDATE
    collection_slug = VALUES(collection_slug),
    title           = VALUES(title),
    character_name  = VALUES(character_name),
    edition         = VALUES(edition),
    artist          = VALUES(artist),
    cip25_json      = VALUES(cip25_json),
    updated_at      = CURRENT_TIMESTAMP;

-- -----------------------------------------------------------------------------
-- Verification
-- -----------------------------------------------------------------------------
-- SELECT rarefolio_token_id, title, character_name, primary_sale_status, custody_status
-- FROM qd_tokens
-- WHERE collection_slug = 'silverbar-01-founders'
-- ORDER BY rarefolio_token_id;
--
-- Expected: 8 rows, ids qd-silver-0000705..0712, all primary_sale_status='unminted'.
