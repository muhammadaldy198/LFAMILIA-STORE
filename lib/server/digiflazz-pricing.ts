import { getD1 } from "@/db";
import { ensureLegacyDatabaseColumns } from "@/lib/server/database-repair";
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

type CachedPriceRow = {
  buyer_sku_code: string;
  product_name: string;
  category: string;
  brand: string;
  type: string;
  seller_name: string;
  price: number;
  buyer_product_status: number;
  seller_product_status: number;
  unlimited_stock: number;
  stock: number;
  multi: number;
  start_cut_off: string;
  end_cut_off: string;
  description: string;
  synced_at: string;
};

export async function ensureDigiflazzPriceListCacheTable() {
  await getD1().prepare(`
    CREATE TABLE IF NOT EXISTS digiflazz_pricelist_cache (
      buyer_sku_code TEXT PRIMARY KEY,
      product_name TEXT NOT NULL,
      category TEXT NOT NULL DEFAULT '',
      brand TEXT NOT NULL DEFAULT '',
      type TEXT NOT NULL DEFAULT '',
      seller_name TEXT NOT NULL DEFAULT '',
      price INTEGER NOT NULL,
      buyer_product_status INTEGER NOT NULL DEFAULT 1,
      seller_product_status INTEGER NOT NULL DEFAULT 1,
      unlimited_stock INTEGER NOT NULL DEFAULT 0,
      stock INTEGER NOT NULL DEFAULT 0,
      multi INTEGER NOT NULL DEFAULT 0,
      start_cut_off TEXT NOT NULL DEFAULT '00:00',
      end_cut_off TEXT NOT NULL DEFAULT '00:00',
      description TEXT NOT NULL DEFAULT '',
      synced_at TEXT NOT NULL
    )
  `).run();
  await getD1().prepare("CREATE INDEX IF NOT EXISTS digiflazz_pricelist_cache_brand_idx ON digiflazz_pricelist_cache (brand, product_name)").run();
}

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

  const items = payload.data
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
  if (!items.length) throw new Error("DigiFlazz mengembalikan price list kosong; cache lama dipertahankan.");
  return items;
}

async function writePriceListCache(items: DigiflazzPriceListItem[]) {
  await ensureDigiflazzPriceListCacheTable();
  const db = getD1();
  const syncedAt = new Date().toISOString();
  const statements = items.map((item) => db.prepare(`
    INSERT INTO digiflazz_pricelist_cache (
      buyer_sku_code, product_name, category, brand, type, seller_name, price,
      buyer_product_status, seller_product_status, unlimited_stock, stock, multi,
      start_cut_off, end_cut_off, description, synced_at
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ON CONFLICT(buyer_sku_code) DO UPDATE SET
      product_name = excluded.product_name,
      category = excluded.category,
      brand = excluded.brand,
      type = excluded.type,
      seller_name = excluded.seller_name,
      price = excluded.price,
      buyer_product_status = excluded.buyer_product_status,
      seller_product_status = excluded.seller_product_status,
      unlimited_stock = excluded.unlimited_stock,
      stock = excluded.stock,
      multi = excluded.multi,
      start_cut_off = excluded.start_cut_off,
      end_cut_off = excluded.end_cut_off,
      description = excluded.description,
      synced_at = excluded.synced_at
  `).bind(
    item.buyerSkuCode,
    item.productName,
    item.category,
    item.brand,
    item.type,
    item.sellerName,
    item.price,
    item.buyerProductStatus ? 1 : 0,
    item.sellerProductStatus ? 1 : 0,
    item.unlimitedStock ? 1 : 0,
    item.stock,
    item.multi ? 1 : 0,
    item.startCutOff,
    item.endCutOff,
    item.description,
    syncedAt,
  ));
  for (let index = 0; index < statements.length; index += 100) {
    await db.batch(statements.slice(index, index + 100));
  }
  await db.prepare("DELETE FROM digiflazz_pricelist_cache WHERE synced_at <> ?").bind(syncedAt).run();
  return syncedAt;
}

function mapCachedRow(row: CachedPriceRow): DigiflazzPriceListItem {
  return {
    buyerSkuCode: row.buyer_sku_code,
    productName: row.product_name,
    category: row.category,
    brand: row.brand,
    type: row.type,
    sellerName: row.seller_name,
    price: Number(row.price),
    buyerProductStatus: row.buyer_product_status !== 0,
    sellerProductStatus: row.seller_product_status !== 0,
    unlimitedStock: row.unlimited_stock === 1,
    stock: Number(row.stock),
    multi: row.multi === 1,
    startCutOff: row.start_cut_off,
    endCutOff: row.end_cut_off,
    description: row.description,
  };
}

export async function listDigiflazzPriceList() {
  await ensureDigiflazzPriceListCacheTable();
  const rows = await getD1().prepare(`
    SELECT buyer_sku_code, product_name, category, brand, type, seller_name, price,
           buyer_product_status, seller_product_status, unlimited_stock, stock, multi,
           start_cut_off, end_cut_off, description, synced_at
    FROM digiflazz_pricelist_cache
    ORDER BY brand ASC, product_name ASC, price ASC
  `).all<CachedPriceRow>();
  return rows.results.map(mapCachedRow);
}

export async function getDigiflazzPriceListCacheMeta() {
  await ensureDigiflazzPriceListCacheTable();
  const row = await getD1().prepare("SELECT COUNT(*) AS count, MAX(synced_at) AS synced_at FROM digiflazz_pricelist_cache")
    .first<{ count: number; synced_at: string | null }>();
  return { count: Number(row?.count ?? 0), lastSyncedAt: row?.synced_at ?? null };
}

function priceListMap(items: DigiflazzPriceListItem[]) {
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

async function syncRows(target?: { productId: number; packageSku?: string }, sourceItems?: DigiflazzPriceListItem[]) {
  await ensureLegacyDatabaseColumns();
  await ensureDigiflazzSellerMonitorTable();
  const items = sourceItems ?? await listDigiflazzPriceList();
  if (!items.length) throw new Error("Cache pricelist DigiFlazz masih kosong. Jalankan Sync Pricelist sekali terlebih dahulu.");
  const source = priceListMap(items);
  const query = target
    ? `SELECT p.id, p.provider_sku, p.provider_max_price, p.margin_type, p.margin_value,
        m.seller_name AS previous_seller_name, m.baseline_price AS previous_baseline_price
       FROM product_packages p
       LEFT JOIN digiflazz_seller_monitor m ON m.package_id = p.id
       WHERE p.product_id = ?${target.packageSku ? " AND p.sku = ?" : ""} AND p.provider_code = 'digiflazz' AND p.provider_sku IS NOT NULL`
    : `SELECT p.id, p.provider_sku, p.provider_max_price, p.margin_type, p.margin_value,
        m.seller_name AS previous_seller_name, m.baseline_price AS previous_baseline_price
       FROM product_packages p
       LEFT JOIN digiflazz_seller_monitor m ON m.package_id = p.id
       WHERE p.provider_code = 'digiflazz' AND p.provider_sku IS NOT NULL`;
  const prepared = getD1().prepare(query);
  type SyncRow = {
    id: number;
    provider_sku: string;
    provider_max_price: number | null;
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
    const maxPrice = Number(item.provider_max_price) > 0 ? Number(item.provider_max_price) : Number(sourceItem.price);

    return [
      // Harga seller hanya memperbarui supplier_price. provider_max_price adalah batas modal LFAMILIA
      // dan tidak boleh ikut berubah setiap seller mengganti harga.
      getD1().prepare(`UPDATE product_packages
        SET supplier_price = ?,
            provider_max_price = COALESCE(provider_max_price, ?),
            price = ?,
            supplier_synced_at = CURRENT_TIMESTAMP
        WHERE id = ?`)
        .bind(
          sourceItem.price,
          sourceItem.price,
          sale(maxPrice, item.margin_type, item.margin_value),
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
    throw new Error(target.packageSku ? "SKU nominal tidak ditemukan pada cache pricelist DigiFlazz." : "Tidak ada SKU nominal produk ini pada cache pricelist DigiFlazz.");
  return { updated, skipped: false };
}

export async function syncDigiflazzPrices(options: { force?: boolean } = {}) {
  const settings = await getPricingSettings();
  if (!settings.isAutoSync && !options.force) return { updated: 0, cached: 0, lastSyncedAt: null, skipped: true };
  const items = await fetchPriceListItems();
  const lastSyncedAt = await writePriceListCache(items);
  const result = await syncRows(undefined, items);
  return { ...result, cached: items.length, lastSyncedAt };
}

export async function syncDigiflazzProduct(productId: number) {
  return syncRows({ productId });
}

export async function syncDigiflazzPackage(productId: number, packageSku: string) {
  return syncRows({ productId, packageSku });
}
