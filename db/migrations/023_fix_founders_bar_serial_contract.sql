-- Ensure Founders bar_serial contract fields remain populated after cutovers.
-- Idempotent and safe to re-run.

UPDATE qd_tokens
SET
    cip25_json = JSON_SET(
        JSON_SET(
            COALESCE(cip25_json, JSON_OBJECT()),
            '$.bar_serial',
            'E101837'
        ),
        '$.attributes.bar_serial',
        'E101837'
    ),
    updated_at = NOW()
WHERE collection_slug IN ('silverbar-01-founders-v2', 'silverbar-01-founders')
  AND (
      JSON_UNQUOTE(JSON_EXTRACT(cip25_json, '$.bar_serial')) IS NULL
      OR JSON_UNQUOTE(JSON_EXTRACT(cip25_json, '$.bar_serial')) = ''
      OR JSON_UNQUOTE(JSON_EXTRACT(cip25_json, '$.attributes.bar_serial')) IS NULL
      OR JSON_UNQUOTE(JSON_EXTRACT(cip25_json, '$.attributes.bar_serial')) = ''
  );

UPDATE qd_mint_queue
SET
    cip25_json = JSON_SET(
        JSON_SET(
            COALESCE(cip25_json, JSON_OBJECT()),
            '$.bar_serial',
            'E101837'
        ),
        '$.attributes.bar_serial',
        'E101837'
    ),
    updated_at = NOW()
WHERE collection_slug IN ('silverbar-01-founders-v2', 'silverbar-01-founders')
  AND (
      JSON_UNQUOTE(JSON_EXTRACT(cip25_json, '$.bar_serial')) IS NULL
      OR JSON_UNQUOTE(JSON_EXTRACT(cip25_json, '$.bar_serial')) = ''
      OR JSON_UNQUOTE(JSON_EXTRACT(cip25_json, '$.attributes.bar_serial')) IS NULL
      OR JSON_UNQUOTE(JSON_EXTRACT(cip25_json, '$.attributes.bar_serial')) = ''
  );
