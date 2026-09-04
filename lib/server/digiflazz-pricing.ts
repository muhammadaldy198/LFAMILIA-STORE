import { getD1 } from "@/db";
import { hashHex } from "@/lib/server/crypto";
import { withProviderRelayHeaders } from "@/lib/server/provider-relay";
import { getRuntimeEnv, requireRuntimeValue } from "@/lib/server/runtime-env";

type Env = {
  DIGIFLAZZ_USERNAME?: string;
  DIGIFLAZZ_API_KEY?: string;
  DIGIFLAZZ_PRODUCTION_API_KEY?: string;
  DIGIFLAZZ_PRICE_LIST_URL?: string;
};
export type PricingSettings = { isAutoSync: boolean; marginType: "fixed" | "percent"; marginValue: number };

export async function getPricingSettings(): Promise<PricingSettings> { const row = await getD1().prepare("SELECT is_auto_sync, margin_type, margin_value FROM digiflazz_pricing_settings WHERE id = 1").first<{ is_auto_sync: number; margin_type: "fixed" | "percent"; margin_value: number }>(); return { isAutoSync: row?.is_auto_sync !== 0, marginType: row?.margin_type ?? "fixed", marginValue: row?.margin_value ?? 0 }; }
export async function savePricingSettings(input: PricingSettings) { await getD1().prepare("INSERT INTO digiflazz_pricing_settings (id, is_auto_sync, margin_type, margin_value, updated_at) VALUES (1, ?, ?, ?, CURRENT_TIMESTAMP) ON CONFLICT(id) DO UPDATE SET is_auto_sync = excluded.is_auto_sync, margin_type = excluded.margin_type, margin_value = excluded.margin_value, updated_at = CURRENT_TIMESTAMP").bind(input.isAutoSync ? 1 : 0, input.marginType, input.marginValue).run(); }
function sale(cost: number, type: "fixed" | "percent", value: number) { return type === "percent" ? Math.ceil(cost * (100 + value) / 100) : cost + value; }

export async function syncDigiflazzPrices() {
  const env = getRuntimeEnv<Env>();
  const username = requireRuntimeValue(env.DIGIFLAZZ_USERNAME, "DIGIFLAZZ_USERNAME");
  const developmentKey = requireRuntimeValue(
    env.DIGIFLAZZ_API_KEY,
    "DIGIFLAZZ_API_KEY",
  );
  const key = env.DIGIFLAZZ_PRODUCTION_API_KEY?.trim() || developmentKey;
  const priceListUrl = requireRuntimeValue(env.DIGIFLAZZ_PRICE_LIST_URL, "DIGIFLAZZ_PRICE_LIST_URL");
  const settings = await getPricingSettings(); if (!settings.isAutoSync) return { updated: 0, skipped: true };
  const response = await fetch(priceListUrl, { method: "POST", headers: withProviderRelayHeaders(priceListUrl, { "content-type": "application/json", accept: "application/json" }), body: JSON.stringify({ cmd: "prepaid", username, sign: hashHex("md5", `${username}${key}pricelist`) }), signal: AbortSignal.timeout(20_000) });
  const payload = await response.json() as { data?: Array<{ buyer_sku_code?: string; price?: number; buyer_product_status?: boolean; seller_product_status?: boolean }> }; if (!response.ok || !payload.data) throw new Error("Daftar harga DigiFlazz tidak valid.");
  const source = new Map(payload.data.filter((item) => item.buyer_sku_code && Number.isFinite(item.price)).map((item) => [item.buyer_sku_code!, item]));
  const rows = await getD1().prepare("SELECT id, provider_sku, pricing_mode, margin_type, margin_value FROM product_packages WHERE provider_code = 'digiflazz' AND provider_sku IS NOT NULL").all<{ id: number; provider_sku: string; pricing_mode: string; margin_type: "fixed" | "percent"; margin_value: number }>();
  const updates = rows.results.flatMap((item) => { const sourceItem = source.get(item.provider_sku); if (!sourceItem || !sourceItem.price) return []; const type = item.pricing_mode === "auto" ? item.margin_type : settings.marginType; const value = item.pricing_mode === "auto" ? item.margin_value : settings.marginValue; return [getD1().prepare("UPDATE product_packages SET supplier_price = ?, price = ?, supplier_synced_at = CURRENT_TIMESTAMP, is_active = CASE WHEN ? THEN is_active ELSE 0 END WHERE id = ?").bind(sourceItem.price, sale(sourceItem.price, type, value), sourceItem.buyer_product_status !== false && sourceItem.seller_product_status !== false ? 1 : 0, item.id)]; });
  if (updates.length) await getD1().batch(updates); return { updated: updates.length, skipped: false };
}
