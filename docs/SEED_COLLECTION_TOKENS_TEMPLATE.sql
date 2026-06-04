-- =============================================================================
-- Rarefolio Marketplace, collection token seed template (qd_tokens)
-- =============================================================================
-- This template is intentionally stored in docs so migrate.php does not auto-run
-- it by filename. Copy to db/migrations with a numbered filename when ready.
-- =============================================================================

INSERT INTO qd_tokens
    (rarefolio_token_id, policy_id, asset_name_hex, asset_name_utf8, asset_fingerprint,
     collection_slug, title, character_name, edition, artist,
     custody_status, listing_status, primary_sale_status, secondary_eligible,
     metadata_version, cip25_json)
VALUES
    ('<TOKEN_ID_1>',
     '<POLICY_ID_OR_PLACEHOLDER>',
     '<ASSET_NAME_HEX_1>',
     '<ASSET_NAME_UTF8_1>',
     NULL,
     '<COLLECTION_SLUG>',
     '<TITLE_1>',
     '<CHARACTER_NAME_1>',
     '1/<EDITION_SIZE>',
     '<ARTIST_NAME>',
     'platform', 'none', 'unminted', 1,
     'cip25-v1',
     JSON_OBJECT(
         'name',               '<CHAIN_NAME_1>',
         'image',              'ipfs://<IMAGE_CID_OR_PATH_1>',
         'mediaType',          'image/jpeg',
         'description',        '<DESCRIPTION_1>',
         'bar_serial',         '<BAR_SERIAL>',
         'rarefolio_token_id', '<TOKEN_ID_1>',
         'collection',         '<COLLECTION_SLUG>',
         'edition',            '1/<EDITION_SIZE>',
         'attributes',         JSON_OBJECT(
             'bar_serial', '<BAR_SERIAL>',
             'block',      '<BLOCK_NUM>',
             'archetype',  '<ARCHETYPE_1>'
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

-- Duplicate the insert block for each token in the collection.
-- Keep all qd_tokens inserts in one file to match static contract checks.
