import { z } from "zod";
import { requireAdminSession } from "@/lib/server/admin";
import {
  deletePaymentChannel,
  listPaymentChannels,
  savePaymentChannel,
  syncPaymentChannelsForGateways,
  type PaymentGatewayName,
} from "@/lib/server/payment-channels";
import { isAllowedMediaUrl } from "@/lib/media-url";
import { findPaymentChannel } from "@/lib/payment-methods";
import { isDokuChannelSupported } from "@/lib/server/doku";
import { readWalletSettings } from "@/lib/server/wallet";

const channelSchema = z.object({
  id: z.number().int().positive().nullable().optional(),
  method: z.enum(["va", "ewallet", "qris"]),
  channel: z.string().trim().regex(/^[a-z0-9_]+$/).max(30),
  name: z.string().trim().min(2).max(80),
  description: z.string().trim().max(160),
  imageUrl: z.string().trim().max(500).refine(isAllowedMediaUrl, "URL gambar tidak valid.").optional().or(z.literal("")),
  isActive: z.boolean(),
  sortOrder: z.number().int().min(0).max(10000),
});

async function activeGateways(): Promise<PaymentGatewayName[]> {
  const settings = await readWalletSettings();
  const gateways: PaymentGatewayName[] = [];
  if (settings.dokuCheckoutEnabled) gateways.push("doku");
  return gateways;
}

export async function GET(request: Request) {
  const access = await requireAdminSession(request, "owner");
  if (access instanceof Response) return access;
  const gateways = await activeGateways();
  return Response.json({
    channels: await listPaymentChannels(true),
    gateways,
    gateway: gateways[0] ?? null,
  });
}

export async function POST(request: Request) {
  const access = await requireAdminSession(request, "owner");
  if (access instanceof Response) return access;
  try {
    const raw = await request.json();
    if (raw?.action === "sync") {
      const gateways = await activeGateways();
      if (!gateways.length)
        throw new Error("Aktifkan DOKU untuk checkout terlebih dahulu.");
      return Response.json({
        ok: true,
        ...(await syncPaymentChannelsForGateways(gateways)),
      });
    }
    const input = channelSchema.parse(raw);
    if (!findPaymentChannel(input.method, input.channel) || !isDokuChannelSupported(input.method, input.channel)) {
      throw new Error("Channel pembayaran tidak dikenali sebagai channel DOKU Direct API.");
    }
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
  const access = await requireAdminSession(request, "owner");
  if (access instanceof Response) return access;
  const id = Number(new URL(request.url).searchParams.get("id"));
  if (!Number.isInteger(id) || id < 1)
    return Response.json({ error: "Metode pembayaran tidak valid." }, { status: 400 });
  await deletePaymentChannel(id);
  return Response.json({ ok: true });
}
