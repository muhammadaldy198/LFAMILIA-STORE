import { readPaymentPageSettings } from "@/lib/server/payment-page-settings";

export const dynamic = "force-dynamic";

export async function GET() {
  return Response.json(
    { settings: await readPaymentPageSettings() },
    { headers: { "Cache-Control": "no-store" } },
  );
}
