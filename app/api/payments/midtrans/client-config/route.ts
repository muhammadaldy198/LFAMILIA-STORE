import { getRuntimeEnv, requireRuntimeChoice, requireRuntimeValue } from "@/lib/server/runtime-env";

export const dynamic = "force-dynamic";

type RuntimeEnv = {
  MIDTRANS_ENV?: string;
  MIDTRANS_CLIENT_KEY?: string;
  MIDTRANS_SNAP_SCRIPT_URL?: string;
};

export async function GET() {
  try {
    const runtime = getRuntimeEnv<RuntimeEnv>();
    const environment = requireRuntimeChoice(runtime.MIDTRANS_ENV, "MIDTRANS_ENV", ["sandbox", "production"] as const);
    const clientKey = requireRuntimeValue(runtime.MIDTRANS_CLIENT_KEY, "MIDTRANS_CLIENT_KEY");
    const scriptUrl = requireRuntimeValue(runtime.MIDTRANS_SNAP_SCRIPT_URL, "MIDTRANS_SNAP_SCRIPT_URL");

    return Response.json(
      {
        enabled: true,
        environment,
        clientKey,
        scriptUrl,
      },
      { headers: { "Cache-Control": "no-store" } },
    );
  } catch (error) {
    return Response.json(
      {
        enabled: false,
        error: error instanceof Error ? error.message : "Konfigurasi Midtrans belum lengkap.",
      },
      { status: 503, headers: { "Cache-Control": "no-store" } },
    );
  }
}
