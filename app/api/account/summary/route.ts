import { getCustomerSession } from "@/lib/server/customer-auth";

export const dynamic = "force-dynamic";

export async function GET(request: Request) {
  const customer = await getCustomerSession(request);
  if (!customer) {
    return Response.json(
      { customer: null },
      { status: 401, headers: { "Cache-Control": "no-store" } },
    );
  }
  return Response.json({ customer }, { headers: { "Cache-Control": "no-store" } });
}
