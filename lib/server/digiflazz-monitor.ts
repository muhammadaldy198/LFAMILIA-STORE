import { getD1 } from "@/db";

export type DigiflazzMonitorHealth = "healthy" | "warning" | "critical" | "unknown";

export type DigiflazzSellerSnapshotInput = {
  packageId: number;
  sellerName: string | null;
  currentPrice: number;
  previousSellerName?: string | null;
  previousBaselinePrice?: number | null;
  buyerProductStatus: boolean;
  sellerProductStatus: boolean;
  unlimitedStock: boolean;
  stock: number;
  multi: boolean;
  startCutOff: string;
  endCutOff: string;
  description: string;
};

export type DigiflazzSellerMonitorItem = {
  packageId: number;
  productName: string;
  packageLabel: string;
  providerSku: string;
  sellerName: string | null;
  currentPrice: number | null;
  baselinePrice: number | null;
  buyerProductStatus: boolean;
  sellerProductStatus: boolean;
  unlimitedStock: boolean;
  stock: number;
  multi: boolean;
  startCutOff: string | null;
  endCutOff: string | null;
  description: string | null;
  health: DigiflazzMonitorHealth;
  alertReason: string | null;
  lastCheckedAt: string | null;
};

export async function ensureDigiflazzSellerMonitorTable() {
  await getD1().prepare(`
    CREATE TABLE IF NOT EXISTS digiflazz_seller_monitor (
      package_id INTEGER PRIMARY KEY,
      seller_name TEXT,
      current_price INTEGER,
      baseline_price INTEGER,
      buyer_product_status INTEGER,
      seller_product_status INTEGER,
      unlimited_stock INTEGER,
      stock INTEGER,
      multi INTEGER,
      start_cut_off TEXT,
      end_cut_off TEXT,
      description TEXT,
      health TEXT DEFAULT 'unknown' NOT NULL,
      alert_reason TEXT,
      last_checked_at TEXT DEFAULT CURRENT_TIMESTAMP NOT NULL
    )
  `).run();
  await getD1().prepare(`
    CREATE INDEX IF NOT EXISTS digiflazz_seller_monitor_health_idx
    ON digiflazz_seller_monitor (health, last_checked_at)
  `).run();
}

function parseMinutes(value: string | null | undefined) {
  const match = /^(\d{2}):(\d{2})$/.exec(value ?? "");
  if (!match) return null;
  return Number(match[1]) * 60 + Number(match[2]);
}

function jakartaMinuteNow() {
  const parts = new Intl.DateTimeFormat("en-GB", {
    timeZone: "Asia/Jakarta",
    hour: "2-digit",
    minute: "2-digit",
    hourCycle: "h23",
  }).formatToParts(new Date());
  const hour = Number(parts.find((part) => part.type === "hour")?.value ?? "0");
  const minute = Number(parts.find((part) => part.type === "minute")?.value ?? "0");
  return hour * 60 + minute;
}

function isInsideCutOff(start: string, end: string) {
  const startMinute = parseMinutes(start);
  const endMinute = parseMinutes(end);
  if (startMinute === null || endMinute === null || startMinute === endMinute) return false;
  const now = jakartaMinuteNow();
  if (startMinute < endMinute) return now >= startMinute && now < endMinute;
  return now >= startMinute || now < endMinute;
}

export function evaluateDigiflazzSellerSnapshot(input: DigiflazzSellerSnapshotInput) {
  const sameSeller =
    Boolean(input.sellerName) &&
    input.sellerName === input.previousSellerName &&
    Number(input.previousBaselinePrice) > 0;
  const baselinePrice = sameSeller
    ? Number(input.previousBaselinePrice)
    : input.currentPrice;

  const critical: string[] = [];
  const warnings: string[] = [];

  if (!input.buyerProductStatus) critical.push("Produk Buyer DigiFlazz sedang nonaktif.");
  if (!input.sellerProductStatus) critical.push("Seller DigiFlazz sedang nonaktif.");
  if (!input.unlimitedStock && input.stock <= 0) critical.push("Stok seller habis.");

  if (!input.unlimitedStock && input.stock > 0 && input.stock <= 5) {
    warnings.push(`Stok seller menipis: ${input.stock} tersisa.`);
  }

  if (isInsideCutOff(input.startCutOff, input.endCutOff)) {
    warnings.push(`Seller sedang cut-off ${input.startCutOff}–${input.endCutOff} WIB.`);
  }

  if (baselinePrice > 0 && input.currentPrice > baselinePrice) {
    const increasePercent = ((input.currentPrice - baselinePrice) / baselinePrice) * 100;
    if (increasePercent >= 3) {
      warnings.push(
        `Harga seller naik ${increasePercent.toFixed(1)}% dari baseline. Cek seller lain di DigiFlazz.`,
      );
    }
  }

  const health: DigiflazzMonitorHealth =
    critical.length > 0 ? "critical" : warnings.length > 0 ? "warning" : "healthy";
  const alertReason = [...critical, ...warnings].join(" ") || null;

  return { baselinePrice, health, alertReason };
}

export function buildDigiflazzSellerMonitorStatement(input: DigiflazzSellerSnapshotInput) {
  const result = evaluateDigiflazzSellerSnapshot(input);
  return getD1().prepare(`
    INSERT INTO digiflazz_seller_monitor (
      package_id, seller_name, current_price, baseline_price,
      buyer_product_status, seller_product_status, unlimited_stock, stock, multi,
      start_cut_off, end_cut_off, description, health, alert_reason, last_checked_at
    )
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)
    ON CONFLICT(package_id) DO UPDATE SET
      seller_name = excluded.seller_name,
      current_price = excluded.current_price,
      baseline_price = excluded.baseline_price,
      buyer_product_status = excluded.buyer_product_status,
      seller_product_status = excluded.seller_product_status,
      unlimited_stock = excluded.unlimited_stock,
      stock = excluded.stock,
      multi = excluded.multi,
      start_cut_off = excluded.start_cut_off,
      end_cut_off = excluded.end_cut_off,
      description = excluded.description,
      health = excluded.health,
      alert_reason = excluded.alert_reason,
      last_checked_at = CURRENT_TIMESTAMP
  `).bind(
    input.packageId,
    input.sellerName,
    input.currentPrice,
    result.baselinePrice,
    input.buyerProductStatus ? 1 : 0,
    input.sellerProductStatus ? 1 : 0,
    input.unlimitedStock ? 1 : 0,
    input.stock,
    input.multi ? 1 : 0,
    input.startCutOff,
    input.endCutOff,
    input.description,
    result.health,
    result.alertReason,
  );
}

export async function readDigiflazzSellerMonitor() {
  await ensureDigiflazzSellerMonitorTable();
  const rows = await getD1().prepare(`
    SELECT
      p.id AS package_id,
      products.name AS product_name,
      p.label AS package_label,
      p.provider_sku,
      m.seller_name,
      m.current_price,
      m.baseline_price,
      m.buyer_product_status,
      m.seller_product_status,
      m.unlimited_stock,
      m.stock,
      m.multi,
      m.start_cut_off,
      m.end_cut_off,
      m.description,
      COALESCE(m.health, 'unknown') AS health,
      m.alert_reason,
      m.last_checked_at
    FROM product_packages p
    JOIN products ON products.id = p.product_id
    LEFT JOIN digiflazz_seller_monitor m ON m.package_id = p.id
    WHERE p.provider_code = 'digiflazz' AND p.provider_sku IS NOT NULL
    ORDER BY
      CASE COALESCE(m.health, 'unknown')
        WHEN 'critical' THEN 0
        WHEN 'warning' THEN 1
        WHEN 'unknown' THEN 2
        ELSE 3
      END,
      products.name ASC,
      p.sort_order ASC
  `).all<{
    package_id: number;
    product_name: string;
    package_label: string;
    provider_sku: string;
    seller_name: string | null;
    current_price: number | null;
    baseline_price: number | null;
    buyer_product_status: number | null;
    seller_product_status: number | null;
    unlimited_stock: number | null;
    stock: number | null;
    multi: number | null;
    start_cut_off: string | null;
    end_cut_off: string | null;
    description: string | null;
    health: DigiflazzMonitorHealth;
    alert_reason: string | null;
    last_checked_at: string | null;
  }>();

  const items: DigiflazzSellerMonitorItem[] = rows.results.map((row) => ({
    packageId: row.package_id,
    productName: row.product_name,
    packageLabel: row.package_label,
    providerSku: row.provider_sku,
    sellerName: row.seller_name,
    currentPrice: row.current_price,
    baselinePrice: row.baseline_price,
    buyerProductStatus: row.buyer_product_status !== 0,
    sellerProductStatus: row.seller_product_status !== 0,
    unlimitedStock: row.unlimited_stock === 1,
    stock: Number(row.stock ?? 0),
    multi: row.multi === 1,
    startCutOff: row.start_cut_off,
    endCutOff: row.end_cut_off,
    description: row.description,
    health: row.health,
    alertReason: row.alert_reason,
    lastCheckedAt: row.last_checked_at,
  }));

  const summary = {
    total: items.length,
    healthy: items.filter((item) => item.health === "healthy").length,
    warning: items.filter((item) => item.health === "warning").length,
    critical: items.filter((item) => item.health === "critical").length,
    unknown: items.filter((item) => item.health === "unknown").length,
  };

  return { items, summary };
}
