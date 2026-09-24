import { publicCustomerPaymentFee } from "@/lib/payment-fees";
import {
  isGatewayChannelSupported,
  listPaymentChannels,
  listPaymentGatewaySettings,
} from "@/lib/server/payment-channels";
import { getActivePaymentModes } from "@/lib/server/payment-mode-config";
import { getConfiguredGatewayBaseReadiness } from "@/lib/server/payment-router";
import { readWalletSettings } from "@/lib/server/wallet";

export async function GET() {
  const [settings, modes, channels, gatewaySettings] = await Promise.all([
    readWalletSettings({ repairSchema: false }),
    getActivePaymentModes(),
    listPaymentChannels(false),
    listPaymentGatewaySettings(),
  ]);

  const gatewayActive = gatewaySettings.some(
    (item) => item.gateway === modes.walletTopupGateway && item.isActive,
  );
  const candidates = settings.automaticTopupEnabled && gatewayActive
    ? channels
    : [];

  const gatewayReadiness = candidates.length
    ? await getConfiguredGatewayBaseReadiness(modes.walletTopupGateway)
    : null;

  const publicChannels = candidates
    .filter((item) => {
      if (!gatewayReadiness?.ready) return false;
      const gatewayConfig = item.gateway === modes.walletTopupGateway
        ? item.gatewayConfig
        : {
            customerFeeEnabled: item.gatewayConfig.customerFeeEnabled ?? "true",
            customerFeeBps: item.gatewayConfig.customerFeeBps ?? "0",
            customerFeeFixed: item.gatewayConfig.customerFeeFixed ?? "0",
          };
      return isGatewayChannelSupported(
        modes.walletTopupGateway,
        item.method,
        item.channel,
        gatewayConfig,
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
    {
      settings: {
        enabled: settings.automaticTopupEnabled && gatewayActive && publicChannels.length > 0,
        minimumAmount: settings.minTopup,
      },
      channels: publicChannels,
    },
    { headers: { "Cache-Control": "public, max-age=10, s-maxage=10, stale-while-revalidate=20" } },
  );
}
