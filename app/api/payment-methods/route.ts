import {
  getIpaymuReadiness,
  isIpaymuChannelSupported,
} from "@/lib/server/ipaymu";
import {
  getMidtransEnvironment,
  getMidtransMode,
  getMidtransReadiness,
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

  const ipaymuReadiness = getIpaymuReadiness();
  if (settings.ipaymuCheckoutEnabled && ipaymuReadiness.ready) {
    gateways.push({
      code: "ipaymu",
      label: "iPaymu",
      midtransMode: null,
      environment: ipaymuReadiness.environment,
      channels: activeChannels.filter((item) =>
        isIpaymuChannelSupported(item.method, item.channel),
      ),
    });
  }

  const midtransReadiness = getMidtransReadiness();
  if (settings.midtransCheckoutEnabled && midtransReadiness.ready) {
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
  }

  const primary = gateways[0] ?? null;
  const allChannels = [
    ...new Map(
      gateways
        .flatMap((gateway) => gateway.channels)
        .map((channel) => [`${channel.method}:${channel.channel}`, channel]),
    ).values(),
  ];

  return Response.json(
    {
      gateway: primary?.code ?? null,
      midtransMode: primary?.midtransMode ?? null,
      environment: primary?.environment ?? null,
      channels: primary?.channels ?? [],
      allChannels,
      gateways,
      fallbackGateway: gateways[1]?.code ?? null,
      readiness: {
        ipaymu: {
          enabled: settings.ipaymuCheckoutEnabled,
          ready: ipaymuReadiness.ready,
        },
        midtrans: {
          enabled: settings.midtransCheckoutEnabled,
          ready: midtransReadiness.ready,
        },
      },
    },
    { headers: { "Cache-Control": "no-store" } },
  );
}
