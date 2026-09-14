import { getDokuReadiness } from "@/lib/server/doku";
import { getMidtransReadiness } from "@/lib/server/midtrans";
import {
  isGatewayChannelSupported,
  listPaymentChannels,
  listPaymentGatewaySettings,
} from "@/lib/server/payment-channels";
import { isProviderRelayConfigured } from "@/lib/server/provider-relay";

export const dynamic = "force-dynamic";

export async function GET() {
  const [channels, gatewaySettings] = await Promise.all([
    listPaymentChannels(false),
    listPaymentGatewaySettings(),
  ]);
  const activeGateway = new Map(gatewaySettings.map((item) => [item.gateway, item.isActive]));
  const dokuReady = getDokuReadiness().ready;
  const midtransReady = getMidtransReadiness().ready && isProviderRelayConfigured("midtrans");

  const activeChannels = channels
    .filter((item) => activeGateway.get(item.gateway) === true)
    .filter((item) => isGatewayChannelSupported(item.gateway, item.method, item.channel))
    .filter((item) => item.gateway === "doku" ? dokuReady : midtransReady)
    .filter((item) => item.gateway !== "midtrans" || item.gatewayConfig.partnerServiceId?.length === 8)
    .map((item) => ({
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
