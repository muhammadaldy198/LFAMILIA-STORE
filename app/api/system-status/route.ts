import { getD1 } from "@/db";
import { neutralizePublicCopy } from "@/lib/public-copy";
import { getDokuCheckoutReadiness } from "@/lib/server/doku-checkout";
import { getMidtransSnapReadiness } from "@/lib/server/midtrans-snap";
import { getPaymentModeOverview } from "@/lib/server/payment-mode-config";
import { getDigiflazzReadiness } from "@/lib/server/providers/digiflazz";
import { readStorefrontSettings } from "@/lib/server/storefront";

export const dynamic = "force-dynamic";

type ServiceState = "operational" | "degraded";

export async function GET() {
  const [settings, paymentModes, doku, midtrans, digiflazz, activeProducts] = await Promise.all([
    readStorefrontSettings(),
    getPaymentModeOverview(),
    getDokuCheckoutReadiness(),
    getMidtransSnapReadiness(),
    getDigiflazzReadiness(),
    getD1().prepare("SELECT COUNT(*) AS count FROM products WHERE is_active = 1").first<{ count?: number }>(),
  ]);
  const paymentsReady = (paymentModes.dokuMode === "checkout" && doku.ready)
    || (paymentModes.midtransMode === "snap" && midtrans.ready);
  const catalogReady = Number(activeProducts?.count || 0) > 0;
  const services: Array<{ id: string; name: string; state: ServiceState; detail: string }> = [
    { id: "catalog", name: "Katalog produk", state: catalogReady ? "operational" : "degraded", detail: catalogReady ? "Produk yang tersedia dapat dipilih dan dipesan." : "Katalog sedang diperbarui." },
    { id: "payments", name: "Pembayaran", state: paymentsReady ? "operational" : "degraded", detail: paymentsReady ? "Kanal pembayaran yang aktif siap digunakan." : "Kanal pembayaran sedang ditinjau." },
    { id: "fulfillment", name: "Pengiriman otomatis", state: digiflazz.ready ? "operational" : "degraded", detail: digiflazz.ready ? "Pesanan otomatis diteruskan setelah pembayaran terverifikasi." : "Sebagian pengiriman otomatis sedang ditinjau." },
    { id: "support", name: "Layanan pelanggan", state: "operational", detail: neutralizePublicCopy(settings.supportHours) },
  ];
  const merchant = {
    legalName: settings.merchantLegalName ? neutralizePublicCopy(settings.merchantLegalName) : "",
    registrationId: settings.merchantRegistrationId ? neutralizePublicCopy(settings.merchantRegistrationId) : "",
    address: settings.merchantAddress ? neutralizePublicCopy(settings.merchantAddress) : "",
  };
  return Response.json({ services, merchant, updatedAt: new Date().toISOString() }, { headers: { "Cache-Control": "no-store" } });
}
