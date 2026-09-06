import { getTurnstilePublicConfig } from "@/lib/server/turnstile";

export const dynamic = "force-dynamic";

export async function GET() {
  try {
    return Response.json(getTurnstilePublicConfig(), {
      headers: { "Cache-Control": "no-store" },
    });
  } catch {
    return Response.json({ enabled: false, siteKey: null }, {
      headers: { "Cache-Control": "no-store" },
    });
  }
}
