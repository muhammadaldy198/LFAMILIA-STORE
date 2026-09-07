import {
  getDokuEnvironment,
  getDokuReadiness,
  isDokuChannelSupported,
} from "@/lib/server/doku";
import {
  listPaymentChannels,
  type ManagedPaymentChannel,
} from "@/lib/server/payment-channels";
import { readWalletSettings } from "@/lib/server/wallet";

export const dynamic = "force-dynamic";

type CheckoutGateway = {
  code: "doku";
  label: string;
  midtransMode: null;
  environment: "sandbox" | "production" | null;
  channels: ManagedPaymentChannel[];
};

export async function GET() {
  const settings = await readWalletSettings();
  const activeChannels = await listPaymentChannels(false);
  const readiness = getDokuReadiness();
  const gateways: CheckoutGateway[] = [];

  if (settings.dokuCheckoutEnabled && readiness.ready) {
    gateways.push({
      code: "doku",
      label: `DOKU Checkout · ${getDokuEnvironment() === "production" ? "Production" : "Sandbox"}`,
      midtransMode: null,
      environment: readiness.environment,
      channels: activeChannels.filter((item) =>
        isDokuChannelSupported(item.method, item.channel),
      ),
    });
  }

  const primary = gateways[0] ?? null;
  return Response.json(
    {
      gateway: primary?.code ?? null,
      midtransMode: null,
      environment: primary?.environment ?? null,
      channels: primary?.channels ?? [],
      allChannels: primary?.channels ?? [],
      gateways,
      fallbackGateway: null,
      readiness: {
        doku: {
          enabled: settings.dokuCheckoutEnabled,
          ready: readiness.ready,
        },
      },
    },
    { headers: { "Cache-Control": "no-store" } },
  );
}
