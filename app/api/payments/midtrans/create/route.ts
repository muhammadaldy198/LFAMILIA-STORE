import { POST as createAutomaticCheckout } from "@/app/api/payments/auto/create/route";

export const dynamic = "force-dynamic";

/**
 * Compatibility endpoint only.
 * Gateway selection is always server-side: iPaymu primary, Midtrans fallback.
 * Customers cannot force Midtrans by calling this legacy route directly.
 */
export async function POST(request: Request) {
  return createAutomaticCheckout(request);
}
