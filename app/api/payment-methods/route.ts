import {
  listPaymentChannels,
  listPaymentGatewaySettings,
} from "@/lib/server/payment-channels";
import { getConfiguredGatewayReadiness } from "@/lib/server/payment-router";

export const dynamic = "force-dynamic";

export async function GET() {
  const [channels, gatewaySettings] = await Promise.all([
    listPaymentChannels(false),
    listPaymentGatewaySettings(),
  ]);
  const activeGateway = new Map(gatewaySettings.map((item) => [item.gateway, item.isActive]));

  const readyChannels = await Promise.all(
    channels
      .filter((item) => activeGateway.get(item.gateway) === true)
      .map(async (item) => {
        const readiness = await getConfiguredGatewayReadiness({
          gateway: item.gateway,
          paymentMethod: item.method,
          paymentChannel: item.channel,
          gatewayConfig: item.gatewayConfig,
        });
        return { item, ready: readiness.ready };
      }),
  );

  const activeChannels = readyChannels
    .filter(({ ready }) => ready)
    .map(({ item }) => ({
      method: item.method,
      channel: item.channel,
      name: item.name,
      description: item.description,
      ...(item.imageUrl ? { imageUrl: item.imageUrl } : {}),
    }));

  return Response.json(
    { channels: activeChannels },
    { headers: { "Cache-Control": "no-store" } },
  );
}
