import { getD1 } from "@/db";
import { ensureLegacyDatabaseColumns } from "@/lib/server/database-repair";

/**
 * Legacy products that already required nickname verification before KokinPay.
 * Only codes present in the current KokinPay game-code list are migrated here.
 */
export const LEGACY_KOKINPAY_GAME_CODES: Readonly<Record<string, string>> = {
  "mobile-legends": "mobile-legends",
  "free-fire": "free-fire",
  "genshin-impact": "genshin-impact",
  valorant: "valorant",
  "pubg-mobile": "pubg-mobile",
  "honor-of-kings": "honor-of-kings",
  "call-of-duty-mobile": "call-of-duty-mobile",
  "wild-rift": "league-of-legends-wild-rift",
  "arena-of-valor": "arena-of-valor",
  "fc-mobile": "fc-mobile",
  "point-blank": "point-blank",
};

const GAME_CODE_BACKFILL_OPERATION_KEY = "kokinpay_nickname_game_code_backfill_0032";
const GENSHIN_SERVER_REPAIR_OPERATION_KEY = "kokinpay_genshin_server_input_repair_0032_v5_case_insensitive_target";
let backfillPromise: Promise<void> | null = null;

type OperationRow = { completed_at: string };

/**
 * Runtime-safe companion to migration 0032. Game-code migration and the later
 * Genshin server-input repair have independent one-time markers so databases
 * that completed an earlier version of 0032 still receive the compatibility
 * repair without re-enabling a game code an Admin intentionally cleared.
 */
export async function ensureKokinpayNicknameGameCodeBackfill(
  options: { repairSchema?: boolean } = {},
) {
  if (!backfillPromise) {
    backfillPromise = (async () => {
      if (options.repairSchema !== false) await ensureLegacyDatabaseColumns();
      const db = getD1();
      const [, gameBackfillResult, genshinRepairResult] = await db.batch([
        db.prepare(`CREATE TABLE IF NOT EXISTS one_time_operations (
          operation_key TEXT PRIMARY KEY NOT NULL,
          completed_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )`),
        db.prepare(
          "SELECT completed_at FROM one_time_operations WHERE operation_key = ? LIMIT 1",
        ).bind(GAME_CODE_BACKFILL_OPERATION_KEY),
        db.prepare(
          "SELECT completed_at FROM one_time_operations WHERE operation_key = ? LIMIT 1",
        ).bind(GENSHIN_SERVER_REPAIR_OPERATION_KEY),
      ]);
      const gameBackfillCompleted = (gameBackfillResult.results as OperationRow[])[0]?.completed_at;
      const genshinRepairCompleted = (genshinRepairResult.results as OperationRow[])[0]?.completed_at;
      if (gameBackfillCompleted && genshinRepairCompleted) return;

      const statements: D1PreparedStatement[] = [];
      if (!gameBackfillCompleted) {
        statements.push(
          db.prepare(`UPDATE products
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
              )`),
          db.prepare(
            `INSERT INTO one_time_operations (operation_key, completed_at)
             VALUES (?, CURRENT_TIMESTAMP)
             ON CONFLICT(operation_key) DO UPDATE SET completed_at = excluded.completed_at`,
          ).bind(GAME_CODE_BACKFILL_OPERATION_KEY),
        );
      }

      if (!genshinRepairCompleted) {
        statements.push(
          db.prepare(`UPDATE products
            SET needs_server = 1,
                target_template = CASE
                  WHEN instr(lower(target_template), '{{server}}') > 0 THEN target_template
                  WHEN instr(lower(target_template), '{{destination}}') > 0
                    THEN substr(target_template, 1, instr(lower(target_template), '{{destination}}') - 1)
                      || '{{destination}}{{server}}'
                      || substr(target_template, instr(lower(target_template), '{{destination}}') + length('{{destination}}'))
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
              AND nickname_game_code = 'genshin-impact'`),
          db.prepare(
            `INSERT INTO one_time_operations (operation_key, completed_at)
             VALUES (?, CURRENT_TIMESTAMP)
             ON CONFLICT(operation_key) DO UPDATE SET completed_at = excluded.completed_at`,
          ).bind(GENSHIN_SERVER_REPAIR_OPERATION_KEY),
        );
      }

      if (statements.length) await db.batch(statements);
    })().catch((error) => {
      backfillPromise = null;
      throw error;
    });
  }
  await backfillPromise;
}
