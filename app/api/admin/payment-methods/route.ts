import { z } from "zod";
import { requireAdminSession } from "@/lib/server/admin";
import {
  deletePaymentChannel,
  isGatewayChannelSupported,
  listPaymentChannels,
  listPaymentGatewaySettings,
  savePaymentChannel,
  savePaymentGatewayStatus,
  syncPaymentChannelsForGateways,
  type PaymentGatewayName,
} from "@/lib/server/payment-channels";
import { isAllowedMediaUrl } from "@/lib/media-url";
import { isDokuCheckoutChannelSupported } from "@/lib/server/doku-checkout";
import { getMidtransSnapReadiness } from "@/lib/server/midtrans-snap";
import { getPaymentModeOverview } from "@/lib/server/payment-mode-config";
import { getConfiguredGatewayReadiness } from "@/lib/server/payment-router";

const gatewayConfigSchema = z.record(
  z.string().trim().min(1).max(60),
  z.string().max(500),
).default({});

const channelSchema = z.object({
  id: z.number().int().positive().nullable().optional(),
  method: z.enum(["va", "ewallet", "qris"]),
  channel: z.string().trim().regex(/^[a-z0-9_]+$/).max(30),
  name: z.string().trim().min(2).max(80),
  description: z.string().trim().max(160),
  imageUrl: z.string().trim().max(500).refine(isAllowedMediaUrl, "URL gambar tidak valid.").optional().or(z.literal("")),
  isActive: z.boolean(),
  sortOrder: z.number().int().min(0).max(10000),
  gateway: z.enum(["doku", "midtrans"]),
  gatewayConfig: gatewayConfigSchema,
});

const gatewayStatusSchema = z.object({
  action: z.literal("gateway_status"),
  gateway: z.enum(["doku", "midtrans"]),
  enabled: z.boolean(),
});

const syncSchema = z.object({
  action: z.literal("sync"),
  gateways: z.array(z.enum(["doku", "midtrans"])).min(1).max(2).optional(),
});

function validateChannel(input: z.infer<typeof channelSchema>) {
  const feeEnabled = input.gatewayConfig.customerFeeEnabled;
  if (feeEnabled !== undefined && !/^(?:true|false|1|0)$/i.test(feeEnabled)) {
    throw new Error("Status biaya customer tidak valid.");
  }
  const feeBps = input.gatewayConfig.customerFeeBps;
  if (feeBps !== undefined && (!/^(?:0|[1-9]\d{0,3})$/.test(feeBps) || Number(feeBps) >= 10_000)) {
    throw new Error("Biaya persentase customer harus antara 0 sampai 99,99%.");
  }
  const fixedFee = input.gatewayConfig.customerFeeFixed;
  if (fixedFee !== undefined && (!/^(?:0|[1-9]\d{0,8})$/.test(fixedFee) || Number(fixedFee) > 100_000_000)) {
    throw new Error("Biaya tetap customer harus antara Rp0 sampai Rp100.000.000.");
  }
  if (!input.isActive) return;
  const supported = input.gateway === "doku"
    ? isDokuCheckoutChannelSupported(input.method, input.channel, input.gatewayConfig)
    : isGatewayChannelSupported(input.gateway, input.method, input.channel, input.gatewayConfig);
  if (!supported) {
    throw new Error(input.gateway === "doku"
      ? "Channel ini belum didukung DOKU Checkout."
      : "Isi kode gateway resmi untuk channel custom, atau pilih channel bawaan yang didukung provider.");
  }
}

async function gatewayReadiness() {
  const [modes, midtrans] = await Promise.all([
    getPaymentModeOverview(),
    getMidtransSnapReadiness(),
  ]);
  return {
    doku: {
      ready: modes.dokuCheckoutConfigured,
      environment: modes.dokuEnvironment,
      mode: "checkout" as const,
      reason: modes.dokuCheckoutConfigured ? null : `Kredensial DOKU Checkout ${modes.dokuEnvironment} belum lengkap.`,
    },
    midtrans: { ...midtrans, mode: "snap" as const, relayReady: true },
  };
}

export async function GET(request: Request) {
  const access = await requireAdminSession(request, "admin");
  if (access instanceof Response) return access;
  const gatewaySettings = await listPaymentGatewaySettings();
  const channels = await listPaymentChannels(true);
  const channelsWithReadiness = await Promise.all(channels.map(async (item) => ({
    ...item,
    readiness: await getConfiguredGatewayReadiness({
      gateway: item.gateway,
      paymentMethod: item.method,
      paymentChannel: item.channel,
      gatewayConfig: item.gatewayConfig,
    }),
  })));
  return Response.json({
    channels: channelsWithReadiness,
    gatewaySettings,
    gateways: gatewaySettings.filter((item) => item.isActive).map((item) => item.gateway),
    gatewayReadiness: await gatewayReadiness(),
  });
}

export async function POST(request: Request) {
  const access = await requireAdminSession(request, "admin");
  if (access instanceof Response) return access;
  try {
    const raw = await request.json();
    if (raw?.action === "gateway_status") {
      const input = gatewayStatusSchema.parse(raw);
      await savePaymentGatewayStatus(input.gateway, input.enabled);
      return Response.json({ ok: true, gatewaySettings: await listPaymentGatewaySettings() });
    }
    if (raw?.action === "sync") {
      const input = syncSchema.parse(raw);
      const gateways = (input.gateways ?? ["midtrans", "doku"]) as PaymentGatewayName[];
      return Response.json({ ok: true, ...(await syncPaymentChannelsForGateways(gateways)) });
    }
    const input = channelSchema.parse(raw);
    validateChannel(input);
    const id = await savePaymentChannel(input);
    return Response.json({ ok: true, id });
  } catch (error) {
    return Response.json({
      error: error instanceof z.ZodError
        ? error.issues[0]?.message
        : error instanceof Error
          ? error.message
          : "Metode pembayaran gagal disimpan.",
    }, { status: 400 });
  }
}

export async function DELETE(request: Request) {
  const access = await requireAdminSession(request, "admin");
  if (access instanceof Response) return access;
  const id = Number(new URL(request.url).searchParams.get("id"));
  if (!Number.isInteger(id) || id < 1)
    return Response.json({ error: "Metode pembayaran tidak valid." }, { status: 400 });
  await deletePaymentChannel(id);
  return Response.json({ ok: true });
}
