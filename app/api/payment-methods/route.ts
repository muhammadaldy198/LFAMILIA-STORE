import { isIpaymuChannelSupported } from "@/lib/server/ipaymu";
import {
  getMidtransEnvironment,
  getMidtransMode,
  isMidtransChannelSupported,
} from "@/lib/server/midtrans";
import { listPaymentChannels } from "@/lib/server/payment-channels";
import { readWalletSettings } from "@/lib/server/wallet";

export const dynamic = "force-dynamic";

export async function GET() {
  const settings = await readWalletSettings();

  const gateway = settings.midtransCheckoutEnabled
    ? "midtrans"
    : settings.ipaymuCheckoutEnabled
      ? "ipaymu"
      : null;

  let midtransMode: "snap" | "bisnap" | null = null;
  let environment: "sandbox" | "production" | null = null;

  if (gateway === "midtrans") {
    try {
      midtransMode = getMidtransMode();
      environment = getMidtransEnvironment();
    } catch {
      midtransMode = null;
      environment = null;
    }
  }

  const channels = gateway
    ? (await listPaymentChannels(false)).filter((item) =>
        gateway === "ipaymu"
          ? isIpaymuChannelSupported(item.method, item.channel)
          : isMidtransChannelSupported(item.method, item.channel),
      )
    : [];

  return Response.json(
    {
      gateway,
      midtransMode,
      environment,
      channels,
    },
    { headers: { "Cache-Control": "no-store" } },
  );
}
