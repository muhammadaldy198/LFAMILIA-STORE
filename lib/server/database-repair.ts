import { logServerError } from "@/lib/server/safe-log";
import { getD1 } from "@/db";

let repairPromise: Promise<void> | null = null;

const FINAL_AUDIT_MIGRATION = "0029_final_source_audit_remediation.sql";
const KOKINPAY_NICKNAME_MIGRATION = "0032_kokinpay_nickname_game_codes.sql";
const DIGIFLAZZ_MAX_PRICE_MIGRATION = "0033_digiflazz_max_price.sql";
const FINAL_SCHEMA_OBJECTS = [
  "promotion_reservations",
  "promotion_reservations_expiry_idx",
  "orders_wallet_checkout_key_unique",
  "orders_external_checkout_key_unique",
  "wallet_topups_external_checkout_key_unique",
  "promotion_reservation_voucher_guard",
  "promotion_reservation_flash_guard",
  "promotion_reservation_insert",
  "promotion_reservation_consumed",
  "promotion_reservation_released",
] as const;

const columns: Array<[table: string, column: string, definition: string]> = [
  ["products", "image_url", "image_url TEXT"],
  ["products", "banner_url", "banner_url TEXT"],
  ["products", "description", "description TEXT"],
  ["products", "input_fields_json", "input_fields_json TEXT"],
  ["products", "nickname_game_code", "nickname_game_code TEXT"],
  ["products", "manual_open_time", "manual_open_time TEXT"],
  ["products", "manual_close_time", "manual_close_time TEXT"],
  [
    "products",
    "manual_timezone",
    "manual_timezone TEXT DEFAULT 'Asia/Jakarta' NOT NULL",
  ],
  [
    "products",
    "fulfillment_type",
    "fulfillment_type TEXT DEFAULT 'automatic' NOT NULL",
  ],
  [
    "products",
    "target_template",
    "target_template TEXT DEFAULT '{{destination}}{{server}}' NOT NULL",
  ],
  ["products", "manual_instructions", "manual_instructions TEXT"],
  [
    "products",
    "package_tabs_enabled",
    "package_tabs_enabled INTEGER DEFAULT 0 NOT NULL",
  ],
  [
    "products",
    "package_tabs_json",
    "package_tabs_json TEXT DEFAULT '[]' NOT NULL",
  ],
  ["orders", "base_subtotal", "base_subtotal INTEGER DEFAULT 0 NOT NULL"],
  ["orders", "discount_amount", "discount_amount INTEGER DEFAULT 0 NOT NULL"],
  ["orders", "voucher_code", "voucher_code TEXT"],
  ["orders", "flash_sale_id", "flash_sale_id INTEGER"],
  ["orders", "customer_id", "customer_id TEXT"],
  ["orders", "wallet_checkout_key", "wallet_checkout_key TEXT"],
  ["orders", "external_checkout_key", "external_checkout_key TEXT"],
  ["orders", "customer_inputs_json", "customer_inputs_json TEXT DEFAULT '[]' NOT NULL"],
  ["orders", "delivery_mode", "delivery_mode TEXT"],
  ["orders", "supplier_cost_snapshot", "supplier_cost_snapshot INTEGER"],
  ["orders", "provider_max_price_snapshot", "provider_max_price_snapshot INTEGER"],
  ["orders", "doku_environment", "doku_environment TEXT"],
  ["customer_users", "tier_mode", "tier_mode TEXT DEFAULT 'automatic' NOT NULL"],
  ["customer_users", "tier_override", "tier_override TEXT"],
  ["customer_users", "tier_progress_bonus", "tier_progress_bonus INTEGER DEFAULT 0 NOT NULL"],
  ["store_settings", "discord_url", "discord_url TEXT"],
  ["store_settings", "support_widget_enabled", "support_widget_enabled INTEGER DEFAULT 1 NOT NULL"],
  ["product_packages", "package_group", "package_group TEXT"],
  ["product_packages", "image_url", "image_url TEXT"],
  ["product_packages", "provider_code", "provider_code TEXT"],
  ["product_packages", "provider_sku", "provider_sku TEXT"],
  ["product_packages", "supplier_price", "supplier_price INTEGER"],
  ["product_packages", "provider_max_price", "provider_max_price INTEGER"],
  [
    "product_packages",
    "pricing_mode",
    "pricing_mode TEXT DEFAULT 'manual' NOT NULL",
  ],
  [
    "product_packages",
    "margin_type",
    "margin_type TEXT DEFAULT 'fixed' NOT NULL",
  ],
  [
    "product_packages",
    "margin_value",
    "margin_value INTEGER DEFAULT 0 NOT NULL",
  ],
  ["product_packages", "supplier_synced_at", "supplier_synced_at TEXT"],

  ["orders", "doku_reference_no", "doku_reference_no TEXT"],
  ["orders", "doku_payment_no", "doku_payment_no TEXT"],
  ["orders", "doku_qr_content", "doku_qr_content TEXT"],
  ["orders", "doku_payment_name", "doku_payment_name TEXT"],
  ["orders", "doku_status_checked_at", "doku_status_checked_at TEXT"],
  ["wallet_topups", "doku_reference_no", "doku_reference_no TEXT"],
  ["wallet_topups", "doku_payment_no", "doku_payment_no TEXT"],
  ["wallet_topups", "doku_qr_content", "doku_qr_content TEXT"],
  ["wallet_topups", "doku_payment_name", "doku_payment_name TEXT"],
  ["wallet_topups", "doku_status_checked_at", "doku_status_checked_at TEXT"],
  ["wallet_topups", "doku_environment", "doku_environment TEXT"],
  ["wallet_topups", "external_checkout_key", "external_checkout_key TEXT"],

  ["wallet_topups", "payment_fee", "payment_fee INTEGER DEFAULT 0 NOT NULL"],
  [
    "wallet_topups",
    "payment_total",
    "payment_total INTEGER DEFAULT 0 NOT NULL",
  ],
  [
    "discount_vouchers",
    "reserved_count",
    "reserved_count INTEGER NOT NULL DEFAULT 0",
  ],
  [
    "flash_sales",
    "reserved_count",
    "reserved_count INTEGER NOT NULL DEFAULT 0",
  ],
];

type TableInfo = {
  name: string;
  notnull?: number;
  dflt_value?: string | null;
  pk?: number;
};

function resultRows(result: { results?: unknown[] }) {
  return Array.isArray(result.results) ? result.results as Array<Record<string, unknown>> : [];
}

async function runtimeRepairAlreadyComplete(db: D1Database) {
  try {
    // One D1 batch keeps the normal, already-migrated request path to a single
    // round-trip instead of repeating dozens of PRAGMA/DDL/backfill calls on
    // every fresh Worker isolate.
    const [ledger, products, customers, packages, settings, orders, topups, vouchers, flash, objects] =
      await db.batch([
        db.prepare("SELECT name FROM d1_migrations WHERE name IN (?, ?)").bind(FINAL_AUDIT_MIGRATION, DIGIFLAZZ_MAX_PRICE_MIGRATION),
        db.prepare("PRAGMA table_info(products)"),
        db.prepare("PRAGMA table_info(customer_users)"),
        db.prepare("PRAGMA table_info(product_packages)"),
        db.prepare("PRAGMA table_info(store_settings)"),
        db.prepare("PRAGMA table_info(orders)"),
        db.prepare("PRAGMA table_info(wallet_topups)"),
        db.prepare("PRAGMA table_info(discount_vouchers)"),
        db.prepare("PRAGMA table_info(flash_sales)"),
        db.prepare(`SELECT name FROM sqlite_master WHERE name IN (
          'promotion_reservations',
          'promotion_reservations_expiry_idx',
          'orders_wallet_checkout_key_unique',
          'orders_external_checkout_key_unique',
          'wallet_topups_external_checkout_key_unique',
          'promotion_reservation_voucher_guard',
          'promotion_reservation_flash_guard',
          'promotion_reservation_insert',
          'promotion_reservation_consumed',
          'promotion_reservation_released'
        )`),
      ]);

    if (!resultRows(ledger).some((row) => row.name === FINAL_AUDIT_MIGRATION)) return false;
    if (!resultRows(ledger).some((row) => row.name === DIGIFLAZZ_MAX_PRICE_MIGRATION)) return false;

    const names = (result: { results?: unknown[] }) =>
      new Set(resultRows(result).map((row) => String(row.name ?? "")));
    const tableInfo = new Map<string, { results?: unknown[] }>([
      ["products", products],
      ["customer_users", customers],
      ["product_packages", packages],
      ["store_settings", settings],
      ["orders", orders],
      ["wallet_topups", topups],
      ["discount_vouchers", vouchers],
      ["flash_sales", flash],
    ]);
    for (const [table, column] of columns) {
      const info = tableInfo.get(table);
      if (!info || !names(info).has(column)) return false;
    }

    const hasRequiredCounter = (result: { results?: unknown[] }) => {
      const row = resultRows(result).find((item) => item.name === "reserved_count");
      const normalizedDefault = String(row?.dflt_value ?? "").replace(/[()'"]/g, "").trim();
      return Boolean(row && Number(row.notnull ?? 0) === 1 && normalizedDefault === "0");
    };
    if (!hasRequiredCounter(vouchers) || !hasRequiredCounter(flash)) return false;

    const schemaObjects = new Set(resultRows(objects).map((row) => String(row.name ?? "")));
    return FINAL_SCHEMA_OBJECTS.every((name) => schemaObjects.has(name));
  } catch (error) {
    const message = error instanceof Error ? error.message : String(error);
    if (/no such table|no such column/i.test(message)) return false;
    throw error;
  }
}

/** Repairs columns from old, partially-applied D1 migrations without dropping any data. */
export async function ensureLegacyDatabaseColumns() {
  if (!repairPromise) {
    repairPromise = (async () => {
      const db = getD1();

      if (await runtimeRepairAlreadyComplete(db)) return;

      const messageOf = (error: unknown) =>
        error instanceof Error ? error.message : String(error);

      const tableColumns = async (table: string) =>
        db.prepare(`PRAGMA table_info(${table})`).all<TableInfo>();

      for (const [table, column, definition] of columns) {
        try {
          const info = await tableColumns(table);
          if (!info.results.some((entry) => entry.name === column)) {
            try {
              await db
                .prepare(`ALTER TABLE ${table} ADD COLUMN ${definition}`)
                .run();
            } catch (error) {
              const message = messageOf(error);
              if (/duplicate column name/i.test(message)) {
                const latest = await tableColumns(table);
                if (latest.results.some((entry) => entry.name === column)) continue;
              }
              throw error;
            }
          }
        } catch (error) {
          const message = messageOf(error);
          if (/no such table/i.test(message)) {
            // A missing table is handled by its existing feature migration; never drop or recreate feature data here.
            continue;
          }
          throw error;
        }
      }

      const runSchemaStatement = async (sql: string) => {
        try {
          await db.prepare(sql).run();
        } catch (error) {
          const message = messageOf(error);
          if (/no such table/i.test(message)) return;
          throw error;
        }
      };

      await runSchemaStatement(
        "CREATE UNIQUE INDEX IF NOT EXISTS orders_wallet_checkout_key_unique ON orders(customer_id, wallet_checkout_key)",
      );
      await runSchemaStatement(
        "CREATE UNIQUE INDEX IF NOT EXISTS orders_external_checkout_key_unique ON orders(external_checkout_key) WHERE external_checkout_key IS NOT NULL",
      );
      await runSchemaStatement(
        "CREATE UNIQUE INDEX IF NOT EXISTS wallet_topups_external_checkout_key_unique ON wallet_topups(customer_id, external_checkout_key) WHERE external_checkout_key IS NOT NULL",
      );

      await runSchemaStatement(`CREATE TABLE IF NOT EXISTS promotion_reservations (
        order_id TEXT PRIMARY KEY,
        voucher_code TEXT,
        flash_sale_id INTEGER,
        status TEXT NOT NULL CHECK(status IN ('reserved','consumed','released')),
        expires_at TEXT NOT NULL,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
      )`);
      await runSchemaStatement(
        "CREATE INDEX IF NOT EXISTS promotion_reservations_expiry_idx ON promotion_reservations(status, expires_at)",
      );

      await runSchemaStatement(`CREATE TRIGGER IF NOT EXISTS promotion_reservation_voucher_guard
        BEFORE INSERT ON promotion_reservations
        WHEN NEW.voucher_code IS NOT NULL
        BEGIN
          SELECT CASE WHEN NOT EXISTS (
            SELECT 1 FROM discount_vouchers v
            WHERE v.code = NEW.voucher_code
              AND v.is_active = 1
              AND datetime('now') BETWEEN datetime(v.starts_at) AND datetime(v.ends_at)
              AND (v.usage_limit IS NULL OR v.used_count + v.reserved_count < v.usage_limit)
          ) THEN RAISE(ABORT, 'Voucher sudah tidak tersedia') END;
        END`);
      await runSchemaStatement(`CREATE TRIGGER IF NOT EXISTS promotion_reservation_flash_guard
        BEFORE INSERT ON promotion_reservations
        WHEN NEW.flash_sale_id IS NOT NULL
        BEGIN
          SELECT CASE WHEN NOT EXISTS (
            SELECT 1 FROM flash_sales f
            WHERE f.id = NEW.flash_sale_id
              AND f.is_active = 1
              AND datetime('now') BETWEEN datetime(f.starts_at) AND datetime(f.ends_at)
              AND (f.stock_limit IS NULL OR f.sold_count + f.reserved_count < f.stock_limit)
          ) THEN RAISE(ABORT, 'Flash sale sudah tidak tersedia') END;
        END`);
      await runSchemaStatement(`CREATE TRIGGER IF NOT EXISTS promotion_reservation_insert
        AFTER INSERT ON promotion_reservations
        WHEN NEW.status = 'reserved'
        BEGIN
          UPDATE discount_vouchers SET reserved_count = reserved_count + 1 WHERE code = NEW.voucher_code;
          UPDATE flash_sales SET reserved_count = reserved_count + 1 WHERE id = NEW.flash_sale_id;
        END`);
      await runSchemaStatement(`CREATE TRIGGER IF NOT EXISTS promotion_reservation_consumed
        AFTER UPDATE OF status ON promotion_reservations
        WHEN OLD.status = 'reserved' AND NEW.status = 'consumed'
        BEGIN
          UPDATE discount_vouchers
          SET reserved_count = MAX(0, reserved_count - 1), used_count = used_count + 1, updated_at = CURRENT_TIMESTAMP
          WHERE code = NEW.voucher_code;
          UPDATE flash_sales
          SET reserved_count = MAX(0, reserved_count - 1), sold_count = sold_count + 1, updated_at = CURRENT_TIMESTAMP
          WHERE id = NEW.flash_sale_id;
        END`);
      await runSchemaStatement(`CREATE TRIGGER IF NOT EXISTS promotion_reservation_released
        AFTER UPDATE OF status ON promotion_reservations
        WHEN OLD.status = 'reserved' AND NEW.status = 'released'
        BEGIN
          UPDATE discount_vouchers
          SET reserved_count = MAX(0, reserved_count - 1), updated_at = CURRENT_TIMESTAMP
          WHERE code = NEW.voucher_code;
          UPDATE flash_sales
          SET reserved_count = MAX(0, reserved_count - 1), updated_at = CURRENT_TIMESTAMP
          WHERE id = NEW.flash_sale_id;
        END`);

      await runSchemaStatement(`UPDATE orders
        SET delivery_mode = CASE
          WHEN fulfillment_type = 'manual' THEN 'manual'
          WHEN provider_code = 'voucher-stock' THEN 'voucher'
          ELSE 'direct'
        END
        WHERE delivery_mode IS NULL`);
      await runSchemaStatement(`UPDATE product_packages
        SET provider_max_price = supplier_price
        WHERE provider_code = 'digiflazz'
          AND provider_max_price IS NULL
          AND supplier_price IS NOT NULL`);
      await runSchemaStatement(`UPDATE orders
        SET provider_max_price_snapshot = (
          SELECT pp.provider_max_price
          FROM product_packages pp
          JOIN products p ON p.id = pp.product_id
          WHERE p.slug = orders.product_slug
            AND pp.sku = orders.package_sku
            AND pp.provider_max_price IS NOT NULL
          ORDER BY pp.id DESC
          LIMIT 1
        )
        WHERE provider_max_price_snapshot IS NULL
          AND EXISTS (
            SELECT 1
            FROM product_packages pp
            JOIN products p ON p.id = pp.product_id
            WHERE p.slug = orders.product_slug
              AND pp.sku = orders.package_sku
              AND pp.provider_max_price IS NOT NULL
          )`);
      await runSchemaStatement(`UPDATE orders
        SET supplier_cost_snapshot = (
          SELECT pp.supplier_price
          FROM product_packages pp
          JOIN products p ON p.id = pp.product_id
          WHERE p.slug = orders.product_slug
            AND pp.sku = orders.package_sku
            AND pp.supplier_price IS NOT NULL
          ORDER BY pp.id DESC
          LIMIT 1
        )
        WHERE supplier_cost_snapshot IS NULL
          AND EXISTS (
            SELECT 1
            FROM product_packages pp
            JOIN products p ON p.id = pp.product_id
            WHERE p.slug = orders.product_slug
              AND pp.sku = orders.package_sku
              AND pp.supplier_price IS NOT NULL
          )`);

      // If Wrangler's migration ledger already exists, record 0029 only after the
      // complete target schema is present. This prevents a later Wrangler apply
      // from replaying ALTER TABLE statements that Cloudflare runtime already healed.
      try {
        const requiredColumns: Array<[string, string[]]> = [
          ["products", ["nickname_game_code"]],
          ["product_packages", ["provider_max_price"]],
          ["orders", ["delivery_mode", "supplier_cost_snapshot", "provider_max_price_snapshot", "doku_environment"]],
          ["wallet_topups", ["doku_environment", "external_checkout_key"]],
          ["discount_vouchers", ["reserved_count"]],
          ["flash_sales", ["reserved_count"]],
        ];
        let complete = true;
        for (const [table, required] of requiredColumns) {
          const info = await tableColumns(table);
          const names = new Set(info.results.map((entry) => entry.name));
          if (required.some((column) => !names.has(column))) {
            complete = false;
            break;
          }
        }

        if (complete) {
          const schemaObjects = await db.prepare(
            `SELECT name FROM sqlite_master WHERE name IN (
              'promotion_reservations',
              'promotion_reservations_expiry_idx',
              'orders_wallet_checkout_key_unique',
              'orders_external_checkout_key_unique',
              'wallet_topups_external_checkout_key_unique',
              'promotion_reservation_voucher_guard',
              'promotion_reservation_flash_guard',
              'promotion_reservation_insert',
              'promotion_reservation_consumed',
              'promotion_reservation_released'
            )`,
          ).all<{ name: string }>();
          if (schemaObjects.results.length !== FINAL_SCHEMA_OBJECTS.length) complete = false;
        }

        if (complete) {
          const ledger = await tableColumns("d1_migrations");
          const nameColumn = ledger.results.some((entry) => entry.name === "name");
          const unsupportedRequiredColumn = ledger.results.some(
            (entry) =>
              entry.name !== "name" &&
              Boolean(entry.notnull) &&
              !entry.dflt_value &&
              !entry.pk,
          );
          if (nameColumn && !unsupportedRequiredColumn) {
            await db.prepare(
              "INSERT OR IGNORE INTO d1_migrations (name) VALUES (?)",
            ).bind(FINAL_AUDIT_MIGRATION).run();
            await db.prepare(
              "INSERT OR IGNORE INTO d1_migrations (name) VALUES (?)",
            ).bind(KOKINPAY_NICKNAME_MIGRATION).run();
            await db.prepare(
              "INSERT OR IGNORE INTO d1_migrations (name) VALUES (?)",
            ).bind(DIGIFLAZZ_MAX_PRICE_MIGRATION).run();
          }
        }
      } catch (error) {
        const message = messageOf(error);
        if (!/no such table.*d1_migrations/i.test(message)) {
          logServerError("D1 migration ledger marker gagal:", error);
        }
      }
    })().catch((error) => {
      repairPromise = null;
      throw error;
    });
  }
  return repairPromise;
}
