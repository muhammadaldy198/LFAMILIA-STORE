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
import { findPaymentChannel } from "@/lib/payment-methods";

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
  const known = findPaymentChannel(input.method, input.channel);
  if (!known) throw new Error("Channel pembayaran tidak dikenali.");
  if (known.gateway !== input.gateway || !isGatewayChannelSupported(input.gateway, input.method, input.channel)) {
    throw new Error("Mapping gateway tidak sesuai kebijakan eksklusif LFAMILIA.");
  }
  if (input.gateway === "midtrans" && input.isActive) {
    const partnerServiceId = input.gatewayConfig.partnerServiceId ?? "";
    if (partnerServiceId.length !== 8) {
      throw new Error("Isi Partner Service ID Midtrans 8 karakter (termasuk left-padding spasi) sebelum mengaktifkan VA.");
    }
  }
}

export async function GET(request: Request) {
  const access = await requireAdminSession(request, "admin");
  if (access instanceof Response) return access;
  const gatewaySettings = await listPaymentGatewaySettings();
  return Response.json({
    channels: await listPaymentChannels(true),
    gatewaySettings,
    gateways: gatewaySettings.filter((item) => item.isActive).map((item) => item.gateway),
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
