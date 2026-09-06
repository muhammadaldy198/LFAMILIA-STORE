import { isIpaymuChannelSupported } from "@/lib/server/ipaymu";
import {
  getMidtransEnvironment,
  getMidtransMode,
  isMidtransChannelSupported,
} from "@/lib/server/midtrans";
import {
  listPaymentChannels,
  type ManagedPaymentChannel,
} from "@/lib/server/payment-channels";
import { readWalletSettings } from "@/lib/server/wallet";

export const dynamic = "force-dynamic";

type CheckoutGateway = {
  code: "midtrans" | "ipaymu";
  label: string;
  midtransMode: "snap" | "bisnap" | null;
  environment: "sandbox" | "production" | null;
  channels: ManagedPaymentChannel[];
};

export async function GET() {
  const settings = await readWalletSettings();
  const activeChannels = await listPaymentChannels(false);
  const gateways: CheckoutGateway[] = [];

  // Primary gateway: iPaymu
  if (settings.ipaymuCheckoutEnabled) {
    gateways.push({
      code: "ipaymu",
      label: "iPaymu",
      midtransMode: null,
      environment: null,
      channels: activeChannels.filter((item) =>
        isIpaymuChannelSupported(item.method, item.channel),
      ),
    });
  }

  // Fallback gateway: Midtrans
  if (settings.midtransCheckoutEnabled) {
    try {
      const midtransMode = getMidtransMode();
      const environment = getMidtransEnvironment();
      gateways.push({
        code: "midtrans",
        label: `Midtrans ${midtransMode === "bisnap" ? "BI-SNAP" : "Snap"} · ${environment === "production" ? "Production" : "Sandbox"}`,
        midtransMode,
        environment,
        channels: activeChannels.filter((item) =>
          isMidtransChannelSupported(
            item.method,
            item.channel,
            midtransMode,
          ),
        ),
      });
    } catch {
      // Do not advertise invalid Midtrans configuration.
    }
  }

  const primary = gateways[0] ?? null;

  return Response.json(
    {
      // Primary gateway is always returned first.
      // Checkout clients can use the first gateway and fallback logic can use the rest.
      gateway: primary?.code ?? null,
      midtransMode: primary?.midtransMode ?? null,
      environment: primary?.environment ?? null,
      channels: primary?.channels ?? [],
      gateways,
      fallbackGateway: gateways[1]?.code ?? null,
    },
    { headers: { "Cache-Control": "no-store" } },
  );
}
