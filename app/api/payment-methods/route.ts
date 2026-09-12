import {
  getDokuReadiness,
  isDokuChannelSupported,
} from "@/lib/server/doku";
import {
  listPaymentChannels,
  type ManagedPaymentChannel,
} from "@/lib/server/payment-channels";
import { readWalletSettings } from "@/lib/server/wallet";

export const dynamic = "force-dynamic";

export async function GET() {
  const settings = await readWalletSettings();
  const readiness = getDokuReadiness();
  const activeChannels: ManagedPaymentChannel[] =
    settings.dokuCheckoutEnabled && readiness.ready
      ? (await listPaymentChannels(false)).filter((item) =>
          isDokuChannelSupported(item.method, item.channel),
        )
      : [];

  return Response.json(
    { channels: activeChannels },
    { headers: { "Cache-Control": "no-store" } },
  );
}
