import { getD1 } from "@/db";
import {
  isCutoffActiveAtMinute,
  isDigiflazzSnapshotAvailable,
} from "@/lib/server/final-audit-rules";

export type DigiflazzAvailabilityRow = {
  package_id: number;
  buyer_product_status: number;
  seller_product_status: number;
  unlimited_stock: number;
  stock: number;
  start_cut_off: string | null;
  end_cut_off: string | null;
};

function currentMinutes(date: Date, timeZone: string) {
  const parts = new Intl.DateTimeFormat("en-GB", {
    timeZone,
    hour: "2-digit",
    minute: "2-digit",
    hourCycle: "h23",
  }).formatToParts(date);
  const hour = Number(parts.find((part) => part.type === "hour")?.value ?? 0);
  const minute = Number(parts.find((part) => part.type === "minute")?.value ?? 0);
  return hour * 60 + minute;
}

export function isCutoffActive(
  startCutOff: string | null,
  endCutOff: string | null,
  date = new Date(),
  timeZone = "Asia/Jakarta",
) {
  return isCutoffActiveAtMinute(
    startCutOff,
    endCutOff,
    currentMinutes(date, timeZone),
  );
}

function digiflazzRowAvailable(row: DigiflazzAvailabilityRow, date = new Date()) {
  return isDigiflazzSnapshotAvailable({
    buyerProductStatus: row.buyer_product_status,
    sellerProductStatus: row.seller_product_status,
    unlimitedStock: row.unlimited_stock,
    stock: row.stock,
    startCutOff: row.start_cut_off,
    endCutOff: row.end_cut_off,
    currentMinute: currentMinutes(date, "Asia/Jakarta"),
  });
}

export async function readDigiflazzPackageAvailability() {
  const rows = await getD1()
    .prepare(
      `SELECT package_id, buyer_product_status, seller_product_status,
        unlimited_stock, stock, start_cut_off, end_cut_off
       FROM digiflazz_seller_monitor`,
    )
    .all<DigiflazzAvailabilityRow>();
  return new Map(rows.results.map((row) => [row.package_id, digiflazzRowAvailable(row)]));
}

export async function readAvailableVoucherStockKeys() {
  const rows = await getD1()
    .prepare(
      `SELECT stock_key
       FROM voucher_codes
       WHERE status = 'available'
       GROUP BY stock_key`,
    )
    .all<{ stock_key: string }>();
  return new Set(rows.results.map((row) => row.stock_key));
}

export async function isAutomaticPackageAvailable(input: {
  packageId: number;
  providerCode: string | null;
  providerSku: string | null;
}) {
  if (input.providerCode === "digiflazz") {
    const row = await getD1()
      .prepare(
        `SELECT package_id, buyer_product_status, seller_product_status,
          unlimited_stock, stock, start_cut_off, end_cut_off
         FROM digiflazz_seller_monitor WHERE package_id = ? LIMIT 1`,
      )
      .bind(input.packageId)
      .first<DigiflazzAvailabilityRow>();
    return row ? digiflazzRowAvailable(row) : false;
  }
  if (input.providerCode === "voucher-stock") {
    if (!input.providerSku) return false;
    const row = await getD1()
      .prepare("SELECT 1 AS available FROM voucher_codes WHERE stock_key = ? AND status = 'available' LIMIT 1")
      .bind(input.providerSku)
      .first<{ available: number }>();
    return Boolean(row?.available);
  }
  return false;
}
