import { requireCustomerSession } from "@/lib/server/customer-auth";
import { getMemberTierProfile } from "@/lib/server/member-tiers";

export const dynamic = "force-dynamic";

export async function GET(request: Request) {
  const customer = await requireCustomerSession(request);
  if (customer instanceof Response) return customer;

  const membership = await getMemberTierProfile(customer.id);
  return Response.json({ membership }, { headers: { "Cache-Control": "no-store" } });
}
