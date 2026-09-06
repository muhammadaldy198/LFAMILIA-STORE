import { clearCustomerSessionCookie, deleteCustomerSession } from "@/lib/server/customer-auth";
import { rejectCrossOriginMutation } from "@/lib/server/security";

export async function POST(request: Request) {
  const originBlock = rejectCrossOriginMutation(request);
  if (originBlock) return originBlock;
  await deleteCustomerSession(request).catch(() => undefined);
  return Response.json({ ok: true }, { headers: { "Set-Cookie": clearCustomerSessionCookie() } });
}
