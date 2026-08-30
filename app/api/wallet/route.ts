import { readWalletSettings } from "@/lib/server/wallet";

export async function GET() {
  return Response.json({ settings: await readWalletSettings() }, { headers: { "Cache-Control": "public, max-age=30" } });
}
