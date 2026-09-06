import {
  getIpaymuReadiness,
  isIpaymuChannelSupported,
} from "@/lib/server/ipaymu";
import {
  listPaymentChannels,
  type ManagedPaymentChannel,
} from "@/lib/server/payment-channels";
import { readWalletSettings } from "@/lib/server/wallet";

export const dynamic = "force-dynamic";

type CheckoutGateway = {
  code: "ipaymu";
  label: string;
  environment: "sandbox" | "production" | null;
  channels: ManagedPaymentChannel[];
};

export async function GET() {
  const settings = await readWalletSettings();
  const activeChannels = await listPaymentChannels(false);
  const ipaymuReadiness = getIpaymuReadiness();

  const gateway: CheckoutGateway | null =
    settings.ipaymuCheckoutEnabled && ipaymuReadiness.ready
      ? {
          code: "ipaymu",
          label: "iPaymu",
          environment: ipaymuReadiness.environment,
          channels: activeChannels.filter((item) =>
            isIpaymuChannelSupported(item.method, item.channel),
          ),
        }
      : null;

  return Response.json(
    {
      gateway: gateway?.code ?? null,
      environment: gateway?.environment ?? null,
      channels: gateway?.channels ?? [],
      allChannels: gateway?.channels ?? [],
      gateways: gateway ? [gateway] : [],
      fallbackGateway: null,
      readiness: {
        ipaymu: {
          enabled: settings.ipaymuCheckoutEnabled,
          ready: ipaymuReadiness.ready,
        },
      },
    },
    { headers: { "Cache-Control": "no-store" } },
  );
}
