import { isMidtransChannelSupported } from "@/lib/server/midtrans";
import { listPaymentChannels } from "@/lib/server/payment-channels";

export const dynamic = "force-dynamic";

export async function GET() {
  const channels = (await listPaymentChannels(false)).filter((item) =>
    isMidtransChannelSupported(item.method, item.channel),
  );
  return Response.json(
    { channels },
    { headers: { "Cache-Control": "public, max-age=60" } },
  );
}
