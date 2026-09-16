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

const BACKFILL_OPERATION_KEY = "kokinpay_nickname_game_code_backfill_0032";
let backfillPromise: Promise<void> | null = null;

/**
 * Runtime-safe companion to migration 0032. This covers databases where the
 * compatibility repair added nickname_game_code before Wrangler replays 0032.
 * The backfill is recorded once, so a later Admin decision to clear a code is
 * respected and will not be silently re-enabled on another Worker isolate.
 */
export async function ensureKokinpayNicknameGameCodeBackfill() {
  if (!backfillPromise) {
    backfillPromise = (async () => {
      await ensureLegacyDatabaseColumns();
      const db = getD1();
      await db.prepare(`CREATE TABLE IF NOT EXISTS one_time_operations (
        operation_key TEXT PRIMARY KEY NOT NULL,
        completed_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
      )`).run();
      const completed = await db.prepare(
        "SELECT completed_at FROM one_time_operations WHERE operation_key = ? LIMIT 1",
      ).bind(BACKFILL_OPERATION_KEY).first<{ completed_at: string }>();
      if (completed?.completed_at) return;

      await db.batch([
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
        ).bind(BACKFILL_OPERATION_KEY),
      ]);
    })().catch((error) => {
      backfillPromise = null;
      throw error;
    });
  }
  await backfillPromise;
}
