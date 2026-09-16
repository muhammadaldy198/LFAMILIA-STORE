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

-- KokinPay requires a server value for Genshin Impact. Preserve valid custom
-- fields/labels and any existing provider target tokens. Add/repair the second
-- field that checkout treats as Server and inject {{server}} without dropping
-- other target tokens that may still be required for provider fulfillment.
UPDATE products
SET needs_server = 1,
    target_template = CASE
      WHEN instr(lower(target_template), '{{server}}') > 0 THEN target_template
      WHEN instr(target_template, '{{destination}}') > 0
        THEN replace(target_template, '{{destination}}', '{{destination}}{{server}}')
      WHEN target_template IS NULL OR trim(target_template) = ''
        THEN '{{destination}}{{server}}'
      ELSE target_template || '{{server}}'
    END,
    input_fields_json = CASE
      WHEN input_fields_json IS NULL OR trim(input_fields_json) = '' OR json_valid(input_fields_json) = 0
        THEN '[{"id":"destination","label":"UID","placeholder":"Masukkan UID","required":true},{"id":"server","label":"Server","placeholder":"Masukkan Server","required":true}]'
      WHEN json_type(input_fields_json) <> 'array' OR json_array_length(input_fields_json) = 0
        THEN '[{"id":"destination","label":"UID","placeholder":"Masukkan UID","required":true},{"id":"server","label":"Server","placeholder":"Masukkan Server","required":true}]'
      WHEN json_type(input_fields_json, '$[0]') <> 'object'
        THEN '[{"id":"destination","label":"UID","placeholder":"Masukkan UID","required":true},{"id":"server","label":"Server","placeholder":"Masukkan Server","required":true}]'
      WHEN json_array_length(input_fields_json) = 1
        THEN json_insert(
          input_fields_json,
          '$[#]',
          json_object('id', 'server', 'label', 'Server', 'placeholder', 'Masukkan Server', 'required', 1)
        )
      WHEN json_type(input_fields_json, '$[1]') = 'object'
        THEN json_set(input_fields_json, '$[1].required', 1)
      ELSE json_set(
        input_fields_json,
        '$[1]',
        json_object('id', 'server', 'label', 'Server', 'placeholder', 'Masukkan Server', 'required', 1)
      )
    END
WHERE slug = 'genshin-impact'
  AND nickname_game_code = 'genshin-impact';

-- Track the original game-code backfill and later Genshin compatibility repair
-- independently. The v4 marker makes databases that recorded older repair
-- variants run the preservation-safe target normalization exactly once.
CREATE TABLE IF NOT EXISTS one_time_operations (
  operation_key TEXT PRIMARY KEY NOT NULL,
  completed_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
INSERT INTO one_time_operations (operation_key, completed_at)
VALUES ('kokinpay_nickname_game_code_backfill_0032', CURRENT_TIMESTAMP)
ON CONFLICT(operation_key) DO UPDATE SET completed_at = excluded.completed_at;
INSERT INTO one_time_operations (operation_key, completed_at)
VALUES ('kokinpay_genshin_server_input_repair_0032_v4_preserve_target', CURRENT_TIMESTAMP)
ON CONFLICT(operation_key) DO UPDATE SET completed_at = excluded.completed_at;
