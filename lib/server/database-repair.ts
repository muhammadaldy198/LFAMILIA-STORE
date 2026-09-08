import { getD1 } from "@/db";

let repairPromise: Promise<void> | null = null;

const columns: Array<[table: string, column: string, definition: string]> = [
  ["products", "image_url", "image_url TEXT"],
  ["products", "banner_url", "banner_url TEXT"],
  ["products", "description", "description TEXT"],
  ["products", "input_fields_json", "input_fields_json TEXT"],
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
  ["orders", "customer_inputs_json", "customer_inputs_json TEXT DEFAULT '[]' NOT NULL"],
  ["customer_users", "tier_mode", "tier_mode TEXT DEFAULT 'automatic' NOT NULL"],
  ["customer_users", "tier_override", "tier_override TEXT"],
  ["customer_users", "tier_progress_bonus", "tier_progress_bonus INTEGER DEFAULT 0 NOT NULL"],
  ["store_settings", "discord_url", "discord_url TEXT"],
  ["store_settings", "support_widget_enabled", "support_widget_enabled INTEGER DEFAULT 1 NOT NULL"],
  ["product_packages", "package_group", "package_group TEXT"],
  ["product_packages", "provider_code", "provider_code TEXT"],
  ["product_packages", "provider_sku", "provider_sku TEXT"],
  ["product_packages", "supplier_price", "supplier_price INTEGER"],
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

  ["wallet_topups", "payment_fee", "payment_fee INTEGER DEFAULT 0 NOT NULL"],
  [
    "wallet_topups",
    "payment_total",
    "payment_total INTEGER DEFAULT 0 NOT NULL",
  ],
];

/** Repairs columns from old, partially-applied D1 migrations without dropping any data. */
export async function ensureLegacyDatabaseColumns() {
  if (!repairPromise) {
    repairPromise = (async () => {
      const db = getD1();
      for (const [table, column, definition] of columns) {
        try {
          const info = await db
            .prepare(`PRAGMA table_info(${table})`)
            .all<{ name: string }>();
          if (!info.results.some((entry) => entry.name === column)) {
            await db
              .prepare(`ALTER TABLE ${table} ADD COLUMN ${definition}`)
              .run();
          }
        } catch (error) {
          const message = error instanceof Error ? error.message : String(error);
          if (/no such table/i.test(message)) {
            // A missing table is handled by its existing feature migration; never drop or recreate data here.
            continue;
          }
          throw error;
        }
      }
      await db.prepare(
        "CREATE UNIQUE INDEX IF NOT EXISTS orders_wallet_checkout_key_unique ON orders(customer_id, wallet_checkout_key)",
      ).run();
    })().catch((error) => {
      repairPromise = null;
      throw error;
    });
  }
  return repairPromise;
}
