import { getD1 } from "@/db";
import { hashHex } from "@/lib/server/crypto";
import { withProviderRelayHeaders } from "@/lib/server/provider-relay";
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

export type PricingSettings = {
  isAutoSync: boolean;
};

export async function getPricingSettings(): Promise<PricingSettings> {
  const row = await getD1()
    .prepare(
      "SELECT is_auto_sync FROM digiflazz_pricing_settings WHERE id = 1",
    )
    .first<{
      is_auto_sync: number;
    }>();

  return {
    isAutoSync: row?.is_auto_sync !== 0,
  };
}

export async function savePricingSettings(input: PricingSettings) {
  await getD1()
    .prepare(
      "INSERT INTO digiflazz_pricing_settings (id, is_auto_sync, updated_at) VALUES (1, ?, CURRENT_TIMESTAMP) ON CONFLICT(id) DO UPDATE SET is_auto_sync = excluded.is_auto_sync, updated_at = CURRENT_TIMESTAMP",
    )
    .bind(input.isAutoSync ? 1 : 0)
    .run();
}

function sale(cost: number, type: "fixed" | "percent", value: number) {
  return type === "percent"
    ? Math.ceil((cost * (100 + value)) / 100)
    : cost + value;
}

export async function syncDigiflazzPrices() {
  const env = getRuntimeEnv<Env>();
  const environment = requireRuntimeChoice(
    env.DIGIFLAZZ_ENV,
    "DIGIFLAZZ_ENV",
    ["development", "production"] as const,
  );
  const username = requireRuntimeValue(
    env.DIGIFLAZZ_USERNAME,
    "DIGIFLAZZ_USERNAME",
  );
  const key = requireRuntimeValue(
    environment === "development"
      ? env.DIGIFLAZZ_DEVELOPMENT_API_KEY
      : env.DIGIFLAZZ_PRODUCTION_API_KEY,
    environment === "development"
      ? "DIGIFLAZZ_DEVELOPMENT_API_KEY"
      : "DIGIFLAZZ_PRODUCTION_API_KEY",
  );
  const priceListUrl = requireRuntimeValue(
    environment === "development"
      ? env.DIGIFLAZZ_DEVELOPMENT_PRICE_LIST_URL
      : env.DIGIFLAZZ_PRODUCTION_PRICE_LIST_URL,
    environment === "development"
      ? "DIGIFLAZZ_DEVELOPMENT_PRICE_LIST_URL"
      : "DIGIFLAZZ_PRODUCTION_PRICE_LIST_URL",
  );

  const settings = await getPricingSettings();
  if (!settings.isAutoSync) return { updated: 0, skipped: true };

  const response = await fetch(priceListUrl, {
    method: "POST",
    headers: withProviderRelayHeaders(
      priceListUrl,
      { "content-type": "application/json", accept: "application/json" },
      { provider: "digiflazz", environment },
    ),
    body: JSON.stringify({
      cmd: "prepaid",
      username,
      sign: hashHex("md5", `${username}${key}pricelist`),
    }),
    signal: AbortSignal.timeout(20_000),
  });

  const payload = (await response.json()) as {
    data?: Array<{
      buyer_sku_code?: string;
      price?: number;
      buyer_product_status?: boolean;
      seller_product_status?: boolean;
    }>;
  };

  if (!response.ok || !payload.data)
    throw new Error("Daftar harga DigiFlazz tidak valid.");

  const source = new Map(
    payload.data
      .filter(
        (item) => item.buyer_sku_code && Number.isFinite(item.price),
      )
      .map((item) => [item.buyer_sku_code!, item]),
  );

  const rows = await getD1()
    .prepare(
      "SELECT id, provider_sku, margin_type, margin_value FROM product_packages WHERE provider_code = 'digiflazz' AND provider_sku IS NOT NULL",
    )
    .all<{
      id: number;
      provider_sku: string;
      margin_type: "fixed" | "percent";
      margin_value: number;
    }>();

  const updates = rows.results.flatMap((item) => {
    const sourceItem = source.get(item.provider_sku);
    if (!sourceItem || !sourceItem.price) return [];

    return [
      getD1()
        .prepare(
          "UPDATE product_packages SET supplier_price = ?, price = ?, supplier_synced_at = CURRENT_TIMESTAMP, is_active = CASE WHEN ? THEN is_active ELSE 0 END WHERE id = ?",
        )
        .bind(
          sourceItem.price,
          sale(sourceItem.price, item.margin_type, item.margin_value),
          sourceItem.buyer_product_status !== false &&
            sourceItem.seller_product_status !== false
            ? 1
            : 0,
          item.id,
        ),
    ];
  });

  if (updates.length) await getD1().batch(updates);
  return { updated: updates.length, skipped: false };
}
