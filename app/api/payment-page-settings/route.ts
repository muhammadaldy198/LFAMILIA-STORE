import { neutralizePublicCopy } from "@/lib/public-copy";
import { readPaymentPageSettings } from "@/lib/server/payment-page-settings";

export const dynamic = "force-dynamic";

export async function GET() {
  const settings = await readPaymentPageSettings();
  return Response.json(
    {
      settings: {
        ...settings,
        eyebrow: neutralizePublicCopy(settings.eyebrow),
        pendingTitle: neutralizePublicCopy(settings.pendingTitle),
        paidTitle: neutralizePublicCopy(settings.paidTitle),
        failedTitle: neutralizePublicCopy(settings.failedTitle),
        subtitle: neutralizePublicCopy(settings.subtitle),
        invoiceNoticeTitle: neutralizePublicCopy(settings.invoiceNoticeTitle),
        invoiceNoticeText: neutralizePublicCopy(settings.invoiceNoticeText),
        pendingStatusText: neutralizePublicCopy(settings.pendingStatusText),
        paidStatusText: neutralizePublicCopy(settings.paidStatusText),
        failedStatusText: neutralizePublicCopy(settings.failedStatusText),
        payButtonText: neutralizePublicCopy(settings.payButtonText),
        checkStatusButtonText: neutralizePublicCopy(settings.checkStatusButtonText),
        checkInvoiceButtonText: neutralizePublicCopy(settings.checkInvoiceButtonText),
        supportText: neutralizePublicCopy(settings.supportText),
      },
    },
    { headers: { "Cache-Control": "public, max-age=30, s-maxage=60, stale-while-revalidate=120" } },
  );
}
