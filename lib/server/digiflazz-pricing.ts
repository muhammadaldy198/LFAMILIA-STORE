import { getD1 } from "@/db";
import { hashHex } from "@/lib/server/crypto";
import { providerRelayRequest } from "@/lib/server/provider-relay";
import { buildDigiflazzSellerMonitorStatement, ensureDigiflazzSellerMonitorTable } from "@/lib/server/digiflazz-monitor";
import {
  getRuntimeEnv,
  requireRuntimeChoice,
  requireRuntimeValue,
} from "@/lib/server/runtime-env";

type Env = {
  DIGIFLAZZ_ENV?: string;
  DIGIFLAZZ_USERNAME?: string;
  DIGIFLAZZ_DEVELOPMENT_API_KEY?: string;
  DIGIFLAZZ_PRODUCTION_API_KEY?: string;
  DIGIFLAZZ_DEVELOPMENT_PRICE_LIST_URL?: string;
  DIGIFLAZZ_PRODUCTION_PRICE_LIST_URL?: string;
};

export type PricingSettings = { isAutoSync: boolean };

export async function getPricingSettings(): Promise<PricingSettings> {
  const row = await getD1()
    .prepare("SELECT is_auto_sync FROM digiflazz_pricing_settings WHERE id = 1")
    .first<{ is_auto_sync: number }>();
  return { isAutoSync: row?.is_auto_sync !== 0 };
}

export async function savePricingSettings(input: PricingSettings) {
  await getD1()
    .prepare("INSERT INTO digiflazz_pricing_settings (id, is_auto_sync, updated_at) VALUES (1, ?, CURRENT_TIMESTAMP) ON CONFLICT(id) DO UPDATE SET is_auto_sync = excluded.is_auto_sync, updated_at = CURRENT_TIMESTAMP")
    .bind(input.isAutoSync ? 1 : 0)
    .run();
}

function sale(cost: number, type: "fixed" | "percent", value: number) {
  return type === "percent" ? Math.ceil((cost * (100 + value)) / 100) : cost + value;
}

export type DigiflazzPriceListItem = {
  buyerSkuCode: string;
  productName: string;
  category: string;
  brand: string;
  type: string;
  sellerName: string;
  price: number;
  buyerProductStatus: boolean;
  sellerProductStatus: boolean;
  unlimitedStock: boolean;
  stock: number;
  multi: boolean;
  startCutOff: string;
  endCutOff: string;
  description: string;
};

type RawPriceItem = {
  buyer_sku_code?: string;
  product_name?: string;
  category?: string;
  brand?: string;
  type?: string;
  seller_name?: string;
  price?: number;
  buyer_product_status?: boolean;
  seller_product_status?: boolean;
  unlimited_stock?: boolean;
  stock?: number | string;
  multi?: boolean;
  start_cut_off?: string;
  end_cut_off?: string;
  desc?: string;
};

async function fetchPriceListItems(): Promise<DigiflazzPriceListItem[]> {
  const env = getRuntimeEnv<Env>();
  const environment = requireRuntimeChoice(env.DIGIFLAZZ_ENV, "DIGIFLAZZ_ENV", ["development", "production"] as const);
  const username = requireRuntimeValue(env.DIGIFLAZZ_USERNAME, "DIGIFLAZZ_USERNAME");
  const key = requireRuntimeValue(
    environment === "development" ? env.DIGIFLAZZ_DEVELOPMENT_API_KEY : env.DIGIFLAZZ_PRODUCTION_API_KEY,
    environment === "development" ? "DIGIFLAZZ_DEVELOPMENT_API_KEY" : "DIGIFLAZZ_PRODUCTION_API_KEY",
  );
  const priceListUrl = requireRuntimeValue(
    environment === "development" ? env.DIGIFLAZZ_DEVELOPMENT_PRICE_LIST_URL : env.DIGIFLAZZ_PRODUCTION_PRICE_LIST_URL,
    environment === "development" ? "DIGIFLAZZ_DEVELOPMENT_PRICE_LIST_URL" : "DIGIFLAZZ_PRODUCTION_PRICE_LIST_URL",
  );
  const relay = providerRelayRequest(
    priceListUrl,
    { "content-type": "application/json", accept: "application/json" },
    { provider: "digiflazz", environment },
  );
  const response = await fetch(relay.url, {
    method: "POST",
    headers: relay.headers,
    body: JSON.stringify({ cmd: "prepaid", username, sign: hashHex("md5", `${username}${key}pricelist`) }),
    signal: AbortSignal.timeout(20_000),
  });
  type PriceListError = { rc?: string; message?: string };
  const payload = (await response.json().catch(() => null)) as {
    data?: RawPriceItem[] | PriceListError;
    message?: string;
  } | null;

  if (!response.ok || !payload || !Array.isArray(payload.data)) {
    const providerError = payload?.data && !Array.isArray(payload.data) ? payload.data : null;
    const detail = providerError?.message || payload?.message;
    const rc = providerError?.rc ? ` (RC ${providerError.rc})` : "";
    throw new Error(detail ? `DigiFlazz menolak price list: ${detail}${rc}` : `DigiFlazz price list gagal dengan HTTP ${response.status}.`);
  }

  return payload.data
    .filter((item) => item.buyer_sku_code && Number.isFinite(Number(item.price)))
    .map((item) => ({
      buyerSkuCode: item.buyer_sku_code!.trim(),
      productName: item.product_name?.trim() || item.buyer_sku_code!.trim(),
      category: item.category?.trim() || "",
      brand: item.brand?.trim() || "",
      type: item.type?.trim() || "",
      sellerName: item.seller_name?.trim() || "",
      price: Number(item.price),
      buyerProductStatus: item.buyer_product_status !== false,
      sellerProductStatus: item.seller_product_status !== false,
      unlimitedStock: item.unlimited_stock === true,
      stock: Number(item.stock ?? 0),
      multi: item.multi === true,
      startCutOff: item.start_cut_off || "00:00",
      endCutOff: item.end_cut_off || "00:00",
      description: item.desc?.trim() || "",
    }));
}

export async function listDigiflazzPriceList() {
  return (await fetchPriceListItems()).sort((a, b) =>
    a.brand.localeCompare(b.brand) || a.productName.localeCompare(b.productName) || a.price - b.price,
  );
}

async function fetchPriceList() {
  const items = await fetchPriceListItems();
  return new Map(items.map((item) => [item.buyerSkuCode, {
    buyer_sku_code: item.buyerSkuCode,
    product_name: item.productName,
    category: item.category,
    brand: item.brand,
    type: item.type,
    seller_name: item.sellerName,
    price: item.price,
    buyer_product_status: item.buyerProductStatus,
    seller_product_status: item.sellerProductStatus,
    unlimited_stock: item.unlimitedStock,
    stock: item.stock,
    multi: item.multi,
    start_cut_off: item.startCutOff,
    end_cut_off: item.endCutOff,
    desc: item.description,
  } satisfies RawPriceItem]));
}

async function syncRows(target?: { productId: number; packageSku?: string }) {
  await ensureDigiflazzSellerMonitorTable();
  const source = await fetchPriceList();
  const query = target
    ? `SELECT p.id, p.provider_sku, p.margin_type, p.margin_value,
        m.seller_name AS previous_seller_name, m.baseline_price AS previous_baseline_price
       FROM product_packages p
       LEFT JOIN digiflazz_seller_monitor m ON m.package_id = p.id
       WHERE p.product_id = ?${target.packageSku ? " AND p.sku = ?" : ""} AND p.provider_code = 'digiflazz' AND p.provider_sku IS NOT NULL`
    : `SELECT p.id, p.provider_sku, p.margin_type, p.margin_value,
        m.seller_name AS previous_seller_name, m.baseline_price AS previous_baseline_price
       FROM product_packages p
       LEFT JOIN digiflazz_seller_monitor m ON m.package_id = p.id
       WHERE p.provider_code = 'digiflazz' AND p.provider_sku IS NOT NULL`;
  const prepared = getD1().prepare(query);
  type SyncRow = {
    id: number;
    provider_sku: string;
    margin_type: "fixed" | "percent";
    margin_value: number;
    previous_seller_name: string | null;
    previous_baseline_price: number | null;
  };
  const rows = target
    ? target.packageSku
      ? await prepared.bind(target.productId, target.packageSku).all<SyncRow>()
      : await prepared.bind(target.productId).all<SyncRow>()
    : await prepared.all<SyncRow>();
  if (target && !rows.results.length)
    throw new Error(target.packageSku ? "Nominal DigiFlazz belum memiliki SKU provider yang valid." : "Produk ini belum memiliki nominal DigiFlazz yang dapat disinkronkan.");

  let updated = 0;
  const statements = rows.results.flatMap((item) => {
    const sourceItem = source.get(item.provider_sku);
    if (!sourceItem || !sourceItem.price) return [];
    updated += 1;

    const buyerProductStatus = sourceItem.buyer_product_status !== false;
    const sellerProductStatus = sourceItem.seller_product_status !== false;
    const unlimitedStock = sourceItem.unlimited_stock === true;
    const stock = Number(sourceItem.stock ?? 0);

    return [
      // Status seller tetap dipantau, tetapi tidak boleh mengubah tombol Aktif/Nonaktif katalog milik admin.
      getD1().prepare(`UPDATE product_packages
        SET supplier_price = ?, price = ?, supplier_synced_at = CURRENT_TIMESTAMP
        WHERE id = ?`)
        .bind(
          sourceItem.price,
          sale(sourceItem.price, item.margin_type, item.margin_value),
          item.id,
        ),
      buildDigiflazzSellerMonitorStatement({
        packageId: item.id,
        sellerName: sourceItem.seller_name?.trim() || null,
        currentPrice: Number(sourceItem.price),
        previousSellerName: item.previous_seller_name,
        previousBaselinePrice: item.previous_baseline_price,
        buyerProductStatus,
        sellerProductStatus,
        unlimitedStock,
        stock,
        multi: sourceItem.multi === true,
        startCutOff: sourceItem.start_cut_off || "00:00",
        endCutOff: sourceItem.end_cut_off || "00:00",
        description: sourceItem.desc?.trim() || "",
      }),
    ];
  });
  if (statements.length) await getD1().batch(statements);
  if (target && updated === 0)
    throw new Error(target.packageSku ? "SKU nominal tidak ditemukan pada price list DigiFlazz." : "Tidak ada SKU nominal produk ini pada price list DigiFlazz.");
  return { updated, skipped: false };
}

export async function syncDigiflazzPrices(options: { force?: boolean } = {}) {
  const settings = await getPricingSettings();
  if (!settings.isAutoSync && !options.force) return { updated: 0, skipped: true };
  return syncRows();
}

export async function syncDigiflazzProduct(productId: number) {
  return syncRows({ productId });
}

export async function syncDigiflazzPackage(productId: number, packageSku: string) {
  return syncRows({ productId, packageSku });
}
