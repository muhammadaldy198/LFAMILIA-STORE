import { getD1 } from "@/db";

let repairPromise: Promise<void> | null = null;

const columns: Array<[table: string, column: string, definition: string]> = [
  ["products", "image_url", "image_url TEXT"],
  ["products", "banner_url", "banner_url TEXT"],
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
  ["orders", "customer_inputs_json", "customer_inputs_json TEXT DEFAULT '[]' NOT NULL"],
  ["customer_users", "tier_mode", "tier_mode TEXT DEFAULT 'automatic' NOT NULL"],
  ["customer_users", "tier_override", "tier_override TEXT"],
  ["customer_users", "tier_progress_bonus", "tier_progress_bonus INTEGER DEFAULT 0 NOT NULL"],
  ["store_settings", "discord_url", "discord_url TEXT"],
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
  [
    "wallet_settings",
    "manual_qris_enabled",
    "manual_qris_enabled INTEGER DEFAULT 0 NOT NULL",
  ],
  [
    "wallet_settings",
    "manual_qris_name",
    "manual_qris_name TEXT DEFAULT 'QRIS Manual' NOT NULL",
  ],
  ["wallet_settings", "manual_qris_image_url", "manual_qris_image_url TEXT"],
  [
    "wallet_settings",
    "midtrans_topup_enabled",
    "midtrans_topup_enabled INTEGER DEFAULT 0 NOT NULL",
  ],
  [
    "wallet_settings",
    "midtrans_checkout_enabled",
    "midtrans_checkout_enabled INTEGER DEFAULT 0 NOT NULL",
  ],
  [
    "wallet_settings",
    "ipaymu_topup_enabled",
    "ipaymu_topup_enabled INTEGER DEFAULT 0 NOT NULL",
  ],
  [
    "wallet_settings",
    "ipaymu_checkout_enabled",
    "ipaymu_checkout_enabled INTEGER DEFAULT 0 NOT NULL",
  ],
  ["wallet_topups", "source", "source TEXT DEFAULT 'manual' NOT NULL"],
  ["wallet_topups", "reference_id", "reference_id TEXT"],
  ["wallet_topups", "midtrans_transaction_id", "midtrans_transaction_id TEXT"],
  ["wallet_topups", "midtrans_payment_no", "midtrans_payment_no TEXT"],
  ["wallet_topups", "midtrans_payment_name", "midtrans_payment_name TEXT"],
  ["wallet_topups", "midtrans_payment_url", "midtrans_payment_url TEXT"],
  ["wallet_topups", "midtrans_expired_at", "midtrans_expired_at TEXT"],
  ["wallet_topups", "midtrans_mode", "midtrans_mode TEXT"],
  ["wallet_topups", "ipaymu_transaction_id", "ipaymu_transaction_id TEXT"],
  ["wallet_topups", "ipaymu_payment_no", "ipaymu_payment_no TEXT"],
  ["wallet_topups", "ipaymu_payment_name", "ipaymu_payment_name TEXT"],
  ["wallet_topups", "ipaymu_payment_url", "ipaymu_payment_url TEXT"],
  ["wallet_topups", "ipaymu_expired_at", "ipaymu_expired_at TEXT"],
  ["orders", "midtrans_transaction_id", "midtrans_transaction_id TEXT"],
  ["orders", "midtrans_payment_no", "midtrans_payment_no TEXT"],
  ["orders", "midtrans_payment_name", "midtrans_payment_name TEXT"],
  ["orders", "midtrans_payment_url", "midtrans_payment_url TEXT"],
  ["orders", "midtrans_expired_at", "midtrans_expired_at TEXT"],
  ["orders", "midtrans_mode", "midtrans_mode TEXT"],
  ["orders", "ipaymu_transaction_id", "ipaymu_transaction_id TEXT"],
  ["orders", "ipaymu_payment_no", "ipaymu_payment_no TEXT"],
  ["orders", "ipaymu_payment_name", "ipaymu_payment_name TEXT"],
  ["orders", "ipaymu_payment_url", "ipaymu_payment_url TEXT"],
  ["orders", "ipaymu_expired_at", "ipaymu_expired_at TEXT"],
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
        } catch {
          // A missing table is handled by its existing feature migration; never drop or recreate data here.
        }
      }
    })().catch((error) => {
      repairPromise = null;
      throw error;
    });
  }
  return repairPromise;
}
