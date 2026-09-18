import { getD1 } from "@/db";
import {
  paymentChannels,
  type PaymentChannel,
  type PaymentGatewayCode,
  type PaymentMethodCode,
} from "@/lib/payment-methods";
import { calculateCustomerPaymentFee } from "@/lib/payment-fees";
import { isDokuChannelSupported } from "@/lib/server/doku";
import { isHostedGatewayChannelSupported } from "@/lib/server/hosted-payment-methods";

export type PaymentGatewayName = PaymentGatewayCode;

export type ManagedPaymentChannel = PaymentChannel & {
  id: number | null;
  imageUrl?: string;
  isActive: boolean;
  sortOrder: number;
  gatewayConfig: Record<string, string>;
};

export { calculateCustomerPaymentFee } from "@/lib/payment-fees";

export type PaymentGatewaySetting = {
  gateway: PaymentGatewayName;
  isActive: boolean;
};

const fallback: ManagedPaymentChannel[] = paymentChannels.map((item, index) => ({
  ...item,
  id: null,
  imageUrl: undefined,
  isActive: false,
  sortOrder: index,
  gatewayConfig: {},
}));

function parseGatewayConfig(value: string | null | undefined) {
  if (!value) return {};
  try {
    const parsed = JSON.parse(value) as Record<string, unknown>;
    if (!parsed || typeof parsed !== "object" || Array.isArray(parsed)) return {};
    return Object.fromEntries(
      Object.entries(parsed).flatMap(([key, field]) => typeof field === "string" ? [[key, field]] : []),
    );
  } catch {
    return {};
  }
}

export function isGatewayChannelSupported(
  gateway: PaymentGatewayName,
  method: string,
  channel: string,
  gatewayConfig?: Record<string, string>,
) {
  if (gateway === "doku") return isDokuChannelSupported(method, channel);
  return isHostedGatewayChannelSupported("midtrans", method, channel, gatewayConfig);
}

export async function listPaymentGatewaySettings(): Promise<PaymentGatewaySetting[]> {
  try {
    const rows = await getD1().prepare(
      "SELECT gateway, is_active FROM payment_gateway_settings WHERE gateway IN ('doku', 'midtrans') ORDER BY gateway ASC",
    ).all<{ gateway: PaymentGatewayName; is_active: number }>();
    const byGateway = new Map(rows.results.map((row) => [row.gateway, Boolean(row.is_active)]));
    return (["doku", "midtrans"] as const).map((gateway) => ({
      gateway,
      isActive: byGateway.get(gateway) ?? false,
    }));
  } catch {
    return (["doku", "midtrans"] as const).map((gateway) => ({ gateway, isActive: false }));
  }
}

export async function savePaymentGatewayStatus(gateway: PaymentGatewayName, isActive: boolean) {
  await getD1().prepare(`INSERT INTO payment_gateway_settings (gateway, is_active, updated_at)
    VALUES (?, ?, CURRENT_TIMESTAMP)
    ON CONFLICT(gateway) DO UPDATE SET is_active = excluded.is_active, updated_at = CURRENT_TIMESTAMP`)
    .bind(gateway, isActive ? 1 : 0)
    .run();
}

export async function isPaymentGatewayActive(gateway: PaymentGatewayName) {
  const settings = await listPaymentGatewaySettings();
  return settings.some((item) => item.gateway === gateway && item.isActive);
}

export async function listPaymentChannels(includeInactive = false): Promise<ManagedPaymentChannel[]> {
  try {
    const result = await getD1().prepare(`SELECT id, method, channel, name, description, image_url, is_active, sort_order, gateway, gateway_config_json FROM payment_channels ${includeInactive ? "" : "WHERE is_active = 1"} ORDER BY sort_order ASC, id ASC`).all<{
      id: number;
      method: PaymentMethodCode;
      channel: string;
      name: string;
      description: string;
      image_url: string | null;
      is_active: number;
      sort_order: number;
      gateway: PaymentGatewayName;
      gateway_config_json: string | null;
    }>();
    return result.results.flatMap((item) => {
      if (item.gateway !== "doku" && item.gateway !== "midtrans") return [];
      return [{
        id: item.id,
        method: item.method,
        channel: item.channel,
        name: item.name,
        description: item.description,
        gateway: item.gateway,
        gatewayConfig: {
          customerFeeEnabled: "true",
          customerFeeBps: item.method === "qris" ? "70" : "0",
          customerFeeFixed: "0",
          ...parseGatewayConfig(item.gateway_config_json),
        },
        imageUrl: item.image_url ?? undefined,
        isActive: Boolean(item.is_active),
        sortOrder: item.sort_order,
      } satisfies ManagedPaymentChannel];
    });
  } catch {
    return includeInactive ? fallback : fallback.filter((item) => item.isActive);
  }
}

export async function savePaymentChannel(input: Omit<ManagedPaymentChannel, "id"> & { id?: number | null }) {
  if (input.isActive && !isGatewayChannelSupported(input.gateway, input.method, input.channel, input.gatewayConfig)) {
    throw new Error("Metode tersebut belum memiliki kode pembayaran yang valid untuk gateway yang dipilih.");
  }
  const db = getD1();
  const gatewayConfigJson = JSON.stringify(input.gatewayConfig ?? {});
  const values = [
    input.method,
    input.channel,
    input.name,
    input.description,
    input.imageUrl || null,
    input.isActive ? 1 : 0,
    input.sortOrder,
    input.gateway,
    gatewayConfigJson,
  ];
  if (input.id) {
    await db.prepare("UPDATE payment_channels SET method = ?, channel = ?, name = ?, description = ?, image_url = ?, is_active = ?, sort_order = ?, gateway = ?, gateway_config_json = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?")
      .bind(...values, input.id).run();
    return input.id;
  }
  const row = await db.prepare(`INSERT INTO payment_channels (method, channel, name, description, image_url, is_active, sort_order, gateway, gateway_config_json)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
    ON CONFLICT(method, channel) DO UPDATE SET
      name = excluded.name,
      description = excluded.description,
      image_url = COALESCE(excluded.image_url, payment_channels.image_url),
      is_active = excluded.is_active,
      sort_order = excluded.sort_order,
      gateway = excluded.gateway,
      gateway_config_json = excluded.gateway_config_json,
      updated_at = CURRENT_TIMESTAMP
    RETURNING id`).bind(...values).first<{ id: number }>();
  if (!row) throw new Error("Metode pembayaran gagal disimpan.");
  return row.id;
}

export async function deletePaymentChannel(id: number) {
  await getD1().prepare("DELETE FROM payment_channels WHERE id = ?").bind(id).run();
}

export async function syncPaymentChannelsForGateways(gateways: PaymentGatewayName[]) {
  const activeGateways = [...new Set(gateways)].filter((item): item is PaymentGatewayName => item === "doku" || item === "midtrans");
  if (!activeGateways.length) throw new Error("Pilih minimal satu gateway untuk sinkronisasi.");

  const supported = paymentChannels.filter((item) => activeGateways.includes(item.gateway));
  const db = getD1();
  await db.batch(supported.map((item, index) =>
    db.prepare(`INSERT INTO payment_channels (method, channel, name, description, image_url, is_active, sort_order, gateway, gateway_config_json)
      VALUES (?, ?, ?, ?, NULL, 0, ?, ?, '{}')
      ON CONFLICT(method, channel) DO UPDATE SET
        name = excluded.name,
        description = excluded.description,
        sort_order = excluded.sort_order,
        updated_at = CURRENT_TIMESTAMP`)
      .bind(
        item.method,
        item.channel,
        item.name,
        item.description,
        paymentChannels.findIndex((candidate) => candidate.method === item.method && candidate.channel === item.channel) || index,
        item.gateway,
      ),
  ));
  return {
    gateways: activeGateways,
    mode: "admin-routed" as const,
    synced: supported.length,
    activationPolicy: "manual" as const,
    channels: await listPaymentChannels(true),
  };
}

export async function syncPaymentChannelsForGateway(gateway: PaymentGatewayName) {
  return syncPaymentChannelsForGateways([gateway]);
}

export async function getPaymentChannel(method: string, channel: string, includeInactive = false) {
  const channels = await listPaymentChannels(includeInactive);
  return channels.find((item) => item.method === method && item.channel === channel) ?? null;
}

export async function isPaymentChannelAvailable(method: string, channel: string) {
  const paymentChannel = await getPaymentChannel(method, channel, false);
  if (!paymentChannel || !isGatewayChannelSupported(paymentChannel.gateway, method, channel, paymentChannel.gatewayConfig)) return false;
  return isPaymentGatewayActive(paymentChannel.gateway);
}
