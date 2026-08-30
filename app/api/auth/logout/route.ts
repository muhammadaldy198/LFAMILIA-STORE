import { clearCustomerSessionCookie, deleteCustomerSession } from "@/lib/server/customer-auth";

export async function POST(request: Request) {
  await deleteCustomerSession(request).catch(() => undefined);
  return Response.json({ ok: true }, { headers: { "Set-Cookie": clearCustomerSessionCookie() } });
}
