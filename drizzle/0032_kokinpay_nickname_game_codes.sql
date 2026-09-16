-- Product-specific KokinPay game code. NULL keeps nickname validation disabled for that product.
ALTER TABLE products ADD COLUMN nickname_game_code TEXT;

-- Preserve nickname validation that was already enabled by the legacy policy.
-- Only game codes present in the current KokinPay code list are backfilled.
UPDATE products
SET nickname_game_code = CASE slug
  WHEN 'mobile-legends' THEN 'mobile-legends'
  WHEN 'free-fire' THEN 'free-fire'
  WHEN 'genshin-impact' THEN 'genshin-impact'
  WHEN 'valorant' THEN 'valorant'
  WHEN 'pubg-mobile' THEN 'pubg-mobile'
  WHEN 'honor-of-kings' THEN 'honor-of-kings'
  WHEN 'call-of-duty-mobile' THEN 'call-of-duty-mobile'
  WHEN 'wild-rift' THEN 'league-of-legends-wild-rift'
  WHEN 'arena-of-valor' THEN 'arena-of-valor'
  WHEN 'fc-mobile' THEN 'fc-mobile'
  WHEN 'point-blank' THEN 'point-blank'
  ELSE nickname_game_code
END
WHERE (nickname_game_code IS NULL OR trim(nickname_game_code) = '')
  AND slug IN (
    'mobile-legends', 'free-fire', 'genshin-impact', 'valorant',
    'pubg-mobile', 'honor-of-kings', 'call-of-duty-mobile', 'wild-rift',
    'arena-of-valor', 'fc-mobile', 'point-blank'
  );

-- KokinPay requires a server value for Genshin Impact. Legacy Genshin rows did
-- not collect it, so migrate the checkout contract together with the game code.
UPDATE products
SET needs_server = 1,
    target_template = '{{destination}}{{server}}',
    input_fields_json = CASE
      WHEN input_fields_json IS NULL OR trim(input_fields_json) = '' OR trim(input_fields_json) = '[]'
        THEN '[{"id":"destination","label":"UID","placeholder":"Masukkan UID","required":true},{"id":"server","label":"Server","placeholder":"Masukkan Server","required":true}]'
      ELSE input_fields_json
    END
WHERE slug = 'genshin-impact'
  AND nickname_game_code = 'genshin-impact';

-- Mark the compatibility backfill complete so runtime repair never re-enables
-- nickname validation after an Admin intentionally clears a product game code.
CREATE TABLE IF NOT EXISTS one_time_operations (
  operation_key TEXT PRIMARY KEY NOT NULL,
  completed_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
INSERT INTO one_time_operations (operation_key, completed_at)
VALUES ('kokinpay_nickname_game_code_backfill_0032', CURRENT_TIMESTAMP)
ON CONFLICT(operation_key) DO UPDATE SET completed_at = excluded.completed_at;
