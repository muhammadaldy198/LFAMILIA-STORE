import { handleMidtransBisnapCallback } from "@/lib/server/midtrans-bisnap-callback";

export const dynamic = "force-dynamic";

export async function GET() {
  return Response.json(
    { ok: true, service: "midtrans-bisnap-virtual-account", method: "POST" },
    { headers: { "Cache-Control": "no-store" } },
  );
}

export async function POST(request: Request) {
  return handleMidtransBisnapCallback(request, "virtual-account");
}
