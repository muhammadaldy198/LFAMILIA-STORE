import { publicCustomerPaymentFee } from "@/lib/payment-fees";
import {
  isGatewayChannelSupported,
  listPaymentChannels,
  listPaymentGatewaySettings,
} from "@/lib/server/payment-channels";
import { getConfiguredGatewayBaseReadiness } from "@/lib/server/payment-router";

export const dynamic = "force-dynamic";

export async function GET() {
  const [channels, gatewaySettings] = await Promise.all([
    listPaymentChannels(false),
    listPaymentGatewaySettings(),
  ]);
  const activeGateway = new Map(gatewaySettings.map((item) => [item.gateway, item.isActive]));
  const activeGatewayNames = [...new Set(
    channels
      .filter((item) => activeGateway.get(item.gateway) === true)
      .map((item) => item.gateway),
  )];

  const readinessPairs = await Promise.all(
    activeGatewayNames.map(async (gateway) => [
      gateway,
      await getConfiguredGatewayBaseReadiness(gateway),
    ] as const),
  );
  const readinessByGateway = new Map(readinessPairs);

  const activeChannels = channels
    .filter((item) => {
      const readiness = readinessByGateway.get(item.gateway);
      return Boolean(
        activeGateway.get(item.gateway) === true &&
        readiness?.ready &&
        isGatewayChannelSupported(item.gateway, item.method, item.channel, item.gatewayConfig),
      );
    })
    .map((item) => ({
      method: item.method,
      channel: item.channel,
      name: item.name,
      description: item.description,
      ...publicCustomerPaymentFee(item.gatewayConfig),
      ...(item.imageUrl ? { imageUrl: item.imageUrl } : {}),
    }));

  return Response.json(
    { channels: activeChannels },
    { headers: { "Cache-Control": "public, max-age=10, s-maxage=10, stale-while-revalidate=20" } },
  );
}
