import { requireAdminSession } from "@/lib/server/admin";
import { getIntegrationOverview } from "@/lib/server/integration-config";
import { getMidtransSnapReadiness } from "@/lib/server/midtrans-snap";
import { listPaymentGatewaySettings } from "@/lib/server/payment-channels";
import { getPaymentModeOverview } from "@/lib/server/payment-mode-config";
import { getDigiflazzReadiness } from "@/lib/server/providers/digiflazz";

export const dynamic = "force-dynamic";

type DashboardIntegrationStatus = {
  id: string;
  name: string;
  ready: boolean;
  active: boolean;
  environment: string | null;
  status: string;
};

function statusText(input: { ready: boolean; active: boolean; environment?: string | null }) {
  if (!input.ready) return "Perlu konfigurasi";
  const environment = input.environment
    ? input.environment === "production"
      ? "Production"
      : input.environment === "sandbox"
        ? "Sandbox"
        : input.environment === "development"
          ? "Development"
          : input.environment
    : null;
  const state = input.active ? "Aktif" : "Standby";
  return environment ? `${state} · ${environment}` : state;
}

export async function GET(request: Request) {
  const access = await requireAdminSession(request, "admin");
  if (access instanceof Response) return access;

  try {
    const [integration, paymentModes, gatewaySettings, midtransSnap] = await Promise.all([
      getIntegrationOverview(),
      getPaymentModeOverview(),
      listPaymentGatewaySettings(),
      getMidtransSnapReadiness(),
    ]);

    const activeGateway = new Map(gatewaySettings.map((item) => [item.gateway, item.isActive]));
    const dokuActive = activeGateway.get("doku") === true;
    const midtransActive = activeGateway.get("midtrans") === true;
    const digiflazz = getDigiflazzReadiness();
    const kokinpayReady = integration.profiles.some(
      (profile) => profile.provider === "kokinpay" && profile.mode === "service" && profile.environment === "global" && profile.configured && !profile.decryptionError,
    );

    const items: DashboardIntegrationStatus[] = [
      {
        id: "digiflazz",
        name: "Digiflazz API",
        ready: digiflazz.ready,
        active: true,
        environment: integration.selections.digiflazzEnvironment,
        status: statusText({ ready: digiflazz.ready, active: true, environment: integration.selections.digiflazzEnvironment }),
      },
      {
        id: "kokinpay",
        name: "KokinPay Nickname",
        ready: kokinpayReady,
        active: kokinpayReady,
        environment: null,
        status: statusText({ ready: kokinpayReady, active: kokinpayReady }),
      },
      {
        id: "doku-checkout",
        name: "DOKU Checkout",
        ready: paymentModes.dokuCheckoutConfigured,
        active: dokuActive,
        environment: paymentModes.dokuEnvironment,
        status: statusText({ ready: paymentModes.dokuCheckoutConfigured, active: dokuActive, environment: paymentModes.dokuEnvironment }),
      },
      {
        id: "midtrans-snap",
        name: "Midtrans Snap",
        ready: midtransSnap.ready,
        active: midtransActive,
        environment: paymentModes.midtransEnvironment,
        status: statusText({ ready: midtransSnap.ready, active: midtransActive, environment: paymentModes.midtransEnvironment }),
      },
    ];

    return Response.json({ items }, { headers: { "Cache-Control": "no-store" } });
  } catch (error) {
    return Response.json(
      { error: error instanceof Error ? error.message : "Status integrasi gagal dimuat." },
      { status: 503, headers: { "Cache-Control": "no-store" } },
    );
  }
}
