import {
  getDokuReadiness,
  isDokuChannelSupported,
} from "@/lib/server/doku";
import { listPaymentChannels } from "@/lib/server/payment-channels";
import { readWalletSettings } from "@/lib/server/wallet";

export const dynamic = "force-dynamic";

export async function GET() {
  const settings = await readWalletSettings();
  const readiness = getDokuReadiness();
  const activeChannels =
    settings.dokuCheckoutEnabled && readiness.ready
      ? (await listPaymentChannels(false))
          .filter((item) => isDokuChannelSupported(item.method, item.channel))
          .map((item) => ({
            method: item.method,
            channel: item.channel,
            name: item.name,
            description: item.description,
            ...(item.imageUrl ? { imageUrl: item.imageUrl } : {}),
          }))
      : [];

  return Response.json(
    { channels: activeChannels },
    { headers: { "Cache-Control": "no-store" } },
  );
}
