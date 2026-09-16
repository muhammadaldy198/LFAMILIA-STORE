import { getD1 } from "@/db";

const CONFIG_LOCK_MINUTES = 5;

async function ensureDigiflazzConfigurationGuard() {
  const db = getD1();
  await db.prepare(`
    CREATE TABLE IF NOT EXISTS digiflazz_pricelist_sync_state (
      id INTEGER PRIMARY KEY CHECK (id = 1),
      lock_token TEXT,
      locked_until TEXT,
      last_started_at TEXT,
      last_success_at TEXT
    )
  `).run();
  await db.prepare("INSERT OR IGNORE INTO digiflazz_pricelist_sync_state (id) VALUES (1)").run();
  await db.prepare(`
    CREATE TABLE IF NOT EXISTS digiflazz_runtime_state (
      id INTEGER PRIMARY KEY CHECK (id = 1),
      maintenance_token TEXT,
      maintenance_until TEXT,
      generation INTEGER NOT NULL DEFAULT 1,
      last_changed_at TEXT
    )
  `).run();
  await db.prepare("INSERT OR IGNORE INTO digiflazz_runtime_state (id) VALUES (1)").run();
  await db.prepare(`
    CREATE TRIGGER IF NOT EXISTS digiflazz_order_maintenance_guard
    BEFORE INSERT ON orders
    WHEN NEW.provider_code = 'digiflazz'
      AND EXISTS (
        SELECT 1 FROM digiflazz_runtime_state
        WHERE id = 1
          AND maintenance_token IS NOT NULL
          AND maintenance_until > CURRENT_TIMESTAMP
      )
    BEGIN
      SELECT RAISE(ABORT, 'DIGIFLAZZ_CONFIG_MAINTENANCE');
    END
  `).run();
}

async function clearOwnedLocks(token: string) {
  const db = getD1();
  await db.batch([
    db.prepare(`
      UPDATE digiflazz_runtime_state
         SET maintenance_token = NULL, maintenance_until = NULL
       WHERE id = 1 AND maintenance_token = ?
    `).bind(token),
    db.prepare(`
      UPDATE digiflazz_pricelist_sync_state
         SET lock_token = NULL, locked_until = NULL
       WHERE id = 1 AND lock_token = ?
    `).bind(token),
  ]);
}

export async function acquireDigiflazzConfigurationGuard() {
  await ensureDigiflazzConfigurationGuard();
  const db = getD1();
  const token = crypto.randomUUID();
  const [runtimeLock, syncLock] = await db.batch([
    db.prepare(`
      UPDATE digiflazz_runtime_state
         SET maintenance_token = ?,
             maintenance_until = datetime('now', '+${CONFIG_LOCK_MINUTES} minutes')
       WHERE id = 1
         AND (maintenance_token IS NULL OR maintenance_until IS NULL OR maintenance_until <= CURRENT_TIMESTAMP)
         AND NOT EXISTS (
           SELECT 1 FROM digiflazz_pricelist_sync_state
           WHERE id = 1
             AND lock_token IS NOT NULL
             AND locked_until > CURRENT_TIMESTAMP
         )
         AND NOT EXISTS (
           SELECT 1 FROM orders
           WHERE provider_code = 'digiflazz'
             AND (
               payment_status = 'pending'
               OR (
                 payment_status = 'paid'
                 AND fulfillment_status NOT IN ('success', 'failed', 'cancelled')
               )
             )
         )
    `).bind(token),
    db.prepare(`
      UPDATE digiflazz_pricelist_sync_state
         SET lock_token = ?,
             locked_until = datetime('now', '+${CONFIG_LOCK_MINUTES} minutes')
       WHERE id = 1
         AND (lock_token IS NULL OR locked_until IS NULL OR locked_until <= CURRENT_TIMESTAMP)
         AND EXISTS (
           SELECT 1 FROM digiflazz_runtime_state
           WHERE id = 1 AND maintenance_token = ?
         )
    `).bind(token, token),
  ]);

  if (
    Number(runtimeLock.meta.changes ?? 0) > 0 &&
    Number(syncLock.meta.changes ?? 0) > 0
  ) {
    return token;
  }

  await clearOwnedLocks(token);
  const [orders, runtime, sync] = await db.batch([
    db.prepare(`
      SELECT COUNT(*) AS count FROM orders
      WHERE provider_code = 'digiflazz'
        AND (
          payment_status = 'pending'
          OR (
            payment_status = 'paid'
            AND fulfillment_status NOT IN ('success', 'failed', 'cancelled')
          )
        )
    `),
    db.prepare(`
      SELECT maintenance_token, maintenance_until
      FROM digiflazz_runtime_state WHERE id = 1
    `),
    db.prepare(`
      SELECT lock_token, locked_until
      FROM digiflazz_pricelist_sync_state WHERE id = 1
    `),
  ]);
  const activeOrders = Number((orders.results?.[0] as { count?: number } | undefined)?.count ?? 0);
  const runtimeRow = runtime.results?.[0] as { maintenance_token?: string | null; maintenance_until?: string | null } | undefined;
  const syncRow = sync.results?.[0] as { lock_token?: string | null; locked_until?: string | null } | undefined;

  if (activeOrders > 0) {
    throw new Error(`Konfigurasi DigiFlazz tidak dapat diubah karena masih ada ${activeOrders} pesanan DigiFlazz yang belum terminal.`);
  }
  if (syncRow?.lock_token && syncRow.locked_until) {
    throw new Error("Sync pricelist DigiFlazz sedang berjalan. Tunggu sampai selesai lalu coba lagi.");
  }
  if (runtimeRow?.maintenance_token && runtimeRow.maintenance_until) {
    throw new Error("Perubahan konfigurasi DigiFlazz lain sedang berjalan. Coba lagi beberapa saat.");
  }
  throw new Error("Konfigurasi DigiFlazz belum dapat dikunci dengan aman. Coba lagi.");
}

export async function invalidateDigiflazzOperationalCache(token: string) {
  const db = getD1();
  const ownsGuard = `EXISTS (
    SELECT 1 FROM digiflazz_runtime_state
    WHERE id = 1 AND maintenance_token = ? AND maintenance_until > CURRENT_TIMESTAMP
  )`;
  await db.batch([
    db.prepare(`DELETE FROM digiflazz_pricelist_cache WHERE ${ownsGuard}`).bind(token),
    db.prepare(`DELETE FROM digiflazz_seller_monitor WHERE ${ownsGuard}`).bind(token),
    db.prepare(`
      UPDATE digiflazz_pricelist_sync_state
         SET last_started_at = NULL, last_success_at = NULL
       WHERE id = 1 AND lock_token = ?
    `).bind(token),
  ]);
}

export async function releaseDigiflazzConfigurationGuard(token: string, successful: boolean) {
  const db = getD1();
  await db.batch([
    db.prepare(`
      UPDATE digiflazz_runtime_state
         SET maintenance_token = NULL,
             maintenance_until = NULL,
             generation = CASE WHEN ? = 1 THEN generation + 1 ELSE generation END,
             last_changed_at = CASE WHEN ? = 1 THEN CURRENT_TIMESTAMP ELSE last_changed_at END
       WHERE id = 1 AND maintenance_token = ?
    `).bind(successful ? 1 : 0, successful ? 1 : 0, token),
    db.prepare(`
      UPDATE digiflazz_pricelist_sync_state
         SET lock_token = NULL, locked_until = NULL
       WHERE id = 1 AND lock_token = ?
    `).bind(token),
  ]);
}
