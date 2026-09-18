import { publicCustomerPaymentFee } from "@/lib/payment-fees";
import {
  listPaymentChannels,
  listPaymentGatewaySettings,
} from "@/lib/server/payment-channels";
import { getActivePaymentModes } from "@/lib/server/payment-mode-config";
import { getConfiguredGatewayReadiness } from "@/lib/server/payment-router";
import { readWalletSettings } from "@/lib/server/wallet";

export async function GET() {
  const [settings, modes, channels, gatewaySettings] = await Promise.all([
    readWalletSettings(),
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

  const checked = await Promise.all(candidates.map(async (item) => {
    const gatewayConfig = item.gateway === modes.walletTopupGateway
      ? item.gatewayConfig
      : {
          customerFeeEnabled: item.gatewayConfig.customerFeeEnabled ?? "true",
          customerFeeBps: item.gatewayConfig.customerFeeBps ?? "0",
          customerFeeFixed: item.gatewayConfig.customerFeeFixed ?? "0",
        };
    return {
      item,
      readiness: await getConfiguredGatewayReadiness({
        gateway: modes.walletTopupGateway,
        paymentMethod: item.method,
        paymentChannel: item.channel,
        gatewayConfig,
      }),
    };
  }));

  const publicChannels = checked
    .filter(({ readiness }) => readiness.ready)
    .map(({ item }) => ({
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
    { headers: { "Cache-Control": "no-store" } },
  );
}
