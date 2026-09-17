import { requireAdminSession } from "@/lib/server/admin";
import { getIntegrationOverview } from "@/lib/server/integration-config";
import { getMidtransSnapReadiness } from "@/lib/server/midtrans-snap";
import { getPaymentModeOverview } from "@/lib/server/payment-mode-config";
import { getConfiguredGatewayReadiness } from "@/lib/server/payment-router";
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
    const [integration, paymentModes, dokuDirect, midtransSnap] = await Promise.all([
      getIntegrationOverview(),
      getPaymentModeOverview(),
      getConfiguredGatewayReadiness({ gateway: "doku", paymentMethod: "qris", paymentChannel: "qris" }),
      getMidtransSnapReadiness(),
    ]);

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
        id: "doku-direct",
        name: "DOKU Direct API",
        ready: dokuDirect.ready,
        active: true,
        environment: paymentModes.dokuEnvironment,
        status: statusText({ ready: dokuDirect.ready, active: true, environment: paymentModes.dokuEnvironment }),
      },
      {
        id: "midtrans-snap",
        name: "Midtrans Snap",
        ready: midtransSnap.ready,
        active: true,
        environment: paymentModes.midtransEnvironment,
        status: statusText({ ready: midtransSnap.ready, active: true, environment: paymentModes.midtransEnvironment }),
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
