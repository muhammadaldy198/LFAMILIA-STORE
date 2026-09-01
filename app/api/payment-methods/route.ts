import { listPaymentChannels } from "@/lib/server/payment-channels";
export const dynamic = "force-dynamic";
export async function GET() { return Response.json({ channels: await listPaymentChannels(false) }, { headers: { "Cache-Control": "public, max-age=60" } }); }
