import { requireAdminSession } from "@/lib/server/admin";
import { getDokuCheckoutReadiness } from "@/lib/server/doku-checkout";
import { getIntegrationOverview } from "@/lib/server/integration-config";
import { getMidtransSnapReadiness } from "@/lib/server/midtrans-snap";
import { getPaymentModeOverview } from "@/lib/server/payment-mode-config";
import { getDigiflazzReadiness } from "@/lib/server/providers/digiflazz";

export const dynamic = "force-dynamic";

type DashboardIntegrationStatus = {
  id: string;
  name: string;
  ready: boolean;
  configured?: boolean;
  active: boolean;
  environment: string | null;
  status: string;
};

function statusText(input: { ready: boolean; configured?: boolean; active: boolean; environment?: string | null }) {
  if (!input.ready && !input.configured) return "Perlu konfigurasi";
  const environment = input.environment
    ? input.environment === "production"
      ? "Production"
      : input.environment === "sandbox"
        ? "Sandbox"
        : input.environment === "development"
          ? "Development"
          : input.environment
    : null;
  const state = input.ready ? (input.active ? "Aktif" : "Standby") : "Tersimpan";
  return environment ? `${state} · ${environment}` : state;
}

export async function GET(request: Request) {
  const access = await requireAdminSession(request, "admin");
  if (access instanceof Response) return access;

  try {
    const [integration, paymentModes, dokuCheckout, midtransSnap] = await Promise.all([
      getIntegrationOverview(),
      getPaymentModeOverview(),
      getDokuCheckoutReadiness(),
      getMidtransSnapReadiness(),
    ]);

    const digiflazz = await getDigiflazzReadiness();
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
        ready: dokuCheckout.ready,
        active: paymentModes.dokuMode === "checkout",
        environment: paymentModes.dokuEnvironment,
        status: statusText({ ready: dokuCheckout.ready, active: paymentModes.dokuMode === "checkout", environment: paymentModes.dokuEnvironment }),
      },
      {
        id: "midtrans-snap",
        name: "Midtrans Snap",
        ready: midtransSnap.ready,
        configured: paymentModes.midtransSnapConfigured,
        active: paymentModes.midtransMode === "snap",
        environment: paymentModes.midtransEnvironment,
        status: statusText({ ready: midtransSnap.ready, configured: paymentModes.midtransSnapConfigured, active: paymentModes.midtransMode === "snap", environment: paymentModes.midtransEnvironment }),
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
