import { readWalletSettings } from "@/lib/server/wallet";

export async function GET() {
  const settings = await readWalletSettings();
  return Response.json({ settings: { enabled: settings.dokuTopupEnabled, minimumAmount: settings.minTopup } }, { headers: { "Cache-Control": "no-store" } });
}
