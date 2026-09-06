import { getD1 } from "@/db";
import { paymentChannels, type PaymentChannel, type PaymentMethodCode } from "@/lib/payment-methods";
import { isIpaymuChannelSupported } from "@/lib/server/ipaymu";
import { getMidtransMode, isMidtransChannelSupported } from "@/lib/server/midtrans";

export type ManagedPaymentChannel = PaymentChannel & {
  id: number | null;
  imageUrl?: string;
  isActive: boolean;
  sortOrder: number;
};

export type PaymentGatewayName = "midtrans" | "ipaymu";

const fallback: ManagedPaymentChannel[] = paymentChannels.map((item, index) => ({
  ...item,
  id: null,
  imageUrl: undefined,
  isActive: true,
  sortOrder: index,
}));

export async function listPaymentChannels(includeInactive = false): Promise<ManagedPaymentChannel[]> {
  try {
    const result = await getD1().prepare(`SELECT id, method, channel, name, description, image_url, is_active, sort_order FROM payment_channels ${includeInactive ? "" : "WHERE is_active = 1"} ORDER BY sort_order ASC, id ASC`).all<{
      id: number;
      method: PaymentMethodCode;
      channel: string;
      name: string;
      description: string;
      image_url: string | null;
      is_active: number;
      sort_order: number;
    }>();
    const saved = result.results.map((item) => ({
      id: item.id,
      method: item.method,
      channel: item.channel,
      name: item.name,
      description: item.description,
      imageUrl: item.image_url ?? undefined,
      isActive: Boolean(item.is_active),
      sortOrder: item.sort_order,
    }));
    const savedByChannel = new Map(saved.map((item) => [`${item.method}:${item.channel}`, item]));
    const defaults = new Set(fallback.map((item) => `${item.method}:${item.channel}`));
    const merged = [
      ...fallback.map((item) => savedByChannel.get(`${item.method}:${item.channel}`) ?? item),
      ...saved.filter((item) => !defaults.has(`${item.method}:${item.channel}`)),
    ].sort((a, b) => a.sortOrder - b.sortOrder || (a.id ?? 0) - (b.id ?? 0));
    return includeInactive ? merged : merged.filter((item) => item.isActive);
  } catch {
    return includeInactive ? fallback : fallback.filter((item) => item.isActive);
  }
}

export async function savePaymentChannel(input: Omit<ManagedPaymentChannel, "id"> & { id?: number | null }) {
  const db = getD1();
  const values = [
    input.method,
    input.channel,
    input.name,
    input.description,
    input.imageUrl || null,
    input.isActive ? 1 : 0,
    input.sortOrder,
  ];
  if (input.id) {
    await db.prepare("UPDATE payment_channels SET method = ?, channel = ?, name = ?, description = ?, image_url = ?, is_active = ?, sort_order = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?")
      .bind(...values, input.id).run();
    return input.id;
  }
  const row = await db.prepare(`INSERT INTO payment_channels (method, channel, name, description, image_url, is_active, sort_order)
    VALUES (?, ?, ?, ?, ?, ?, ?)
    ON CONFLICT(method, channel) DO UPDATE SET
      name = excluded.name,
      description = excluded.description,
      image_url = COALESCE(excluded.image_url, payment_channels.image_url),
      is_active = excluded.is_active,
      sort_order = excluded.sort_order,
      updated_at = CURRENT_TIMESTAMP
    RETURNING id`).bind(...values).first<{ id: number }>();
  if (!row) throw new Error("Metode pembayaran gagal disimpan.");
  return row.id;
}

export async function deletePaymentChannel(id: number) {
  await getD1().prepare("DELETE FROM payment_channels WHERE id = ?").bind(id).run();
}

export async function syncPaymentChannelsForGateways(
  gateways: PaymentGatewayName[],
) {
  const activeGateways = [...new Set(gateways)] as PaymentGatewayName[];
  const primaryGateway = activeGateways[0];
  if (!primaryGateway)
    throw new Error("Aktifkan minimal satu payment gateway untuk sinkronisasi.");

  const midtransMode = activeGateways.includes("midtrans")
    ? getMidtransMode()
    : null;
  const supported = paymentChannels.filter((item) =>
    activeGateways.some((gateway) =>
      gateway === "midtrans"
        ? isMidtransChannelSupported(
            item.method,
            item.channel,
            midtransMode!,
          )
        : isIpaymuChannelSupported(item.method, item.channel),
    ),
  );
  const supportedKeys = new Set(
    supported.map((item) => `${item.method}:${item.channel}`),
  );
  const db = getD1();
  await db.batch(paymentChannels.map((item, index) =>
    db.prepare(`INSERT INTO payment_channels (method, channel, name, description, image_url, is_active, sort_order)
      VALUES (?, ?, ?, ?, NULL, ?, ?)
      ON CONFLICT(method, channel) DO UPDATE SET
        is_active = excluded.is_active,
        sort_order = excluded.sort_order,
        updated_at = CURRENT_TIMESTAMP`)
      .bind(
        item.method,
        item.channel,
        item.name,
        item.description,
        supportedKeys.has(`${item.method}:${item.channel}`) ? 1 : 0,
        index,
      ),
  ));
  return {
    gateway: primaryGateway,
    gateways: activeGateways,
    mode: midtransMode,
    synced: supported.length,
    channels: await listPaymentChannels(true),
  };
}

export async function syncPaymentChannelsForGateway(gateway: PaymentGatewayName) {
  return syncPaymentChannelsForGateways([gateway]);
}

export async function isPaymentChannelAvailable(method: string, channel: string) {
  const channels = await listPaymentChannels(false);
  return channels.some((item) => item.method === method && item.channel === channel);
}
