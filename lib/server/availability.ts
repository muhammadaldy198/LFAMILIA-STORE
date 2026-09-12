import { getD1 } from "@/db";

export type DigiflazzAvailabilityRow = {
  package_id: number;
  buyer_product_status: number;
  seller_product_status: number;
  unlimited_stock: number;
  stock: number;
  start_cut_off: string | null;
  end_cut_off: string | null;
};

function parseClock(value: string | null) {
  if (!value || !/^\d{2}:\d{2}$/.test(value)) return null;
  const [hour, minute] = value.split(":").map(Number);
  if (hour > 23 || minute > 59) return null;
  return hour * 60 + minute;
}

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
  const start = parseClock(startCutOff);
  const end = parseClock(endCutOff);
  if (start === null || end === null || start === end) return false;
  const now = currentMinutes(date, timeZone);
  return start < end ? now >= start && now < end : now >= start || now < end;
}

export async function readDigiflazzPackageAvailability() {
  const rows = await getD1()
    .prepare(
      `SELECT package_id, buyer_product_status, seller_product_status,
        unlimited_stock, stock, start_cut_off, end_cut_off
       FROM digiflazz_seller_monitor`,
    )
    .all<DigiflazzAvailabilityRow>();
  return new Map(
    rows.results.map((row) => [
      row.package_id,
      Boolean(
        row.buyer_product_status &&
        row.seller_product_status &&
        (row.unlimited_stock || Number(row.stock) > 0) &&
        !isCutoffActive(row.start_cut_off, row.end_cut_off),
      ),
    ]),
  );
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
