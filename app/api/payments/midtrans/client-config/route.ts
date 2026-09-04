import {
  getRuntimeEnv,
  requireRuntimeChoice,
  requireRuntimeValue,
} from "@/lib/server/runtime-env";

export const dynamic = "force-dynamic";

type RuntimeEnv = {
  MIDTRANS_MODE?: string;
  MIDTRANS_ENV?: string;
  MIDTRANS_SNAP_SANDBOX_CLIENT_KEY?: string;
  MIDTRANS_SNAP_PRODUCTION_CLIENT_KEY?: string;
  MIDTRANS_SNAP_SANDBOX_SCRIPT_URL?: string;
  MIDTRANS_SNAP_PRODUCTION_SCRIPT_URL?: string;
};

export async function GET() {
  try {
    const runtime = getRuntimeEnv<RuntimeEnv>();
    const mode = requireRuntimeChoice(
      runtime.MIDTRANS_MODE,
      "MIDTRANS_MODE",
      ["snap", "bisnap"] as const,
    );
    const environment = requireRuntimeChoice(
      runtime.MIDTRANS_ENV,
      "MIDTRANS_ENV",
      ["sandbox", "production"] as const,
    );

    if (mode !== "snap") {
      return Response.json(
        { enabled: false, mode, environment },
        { headers: { "Cache-Control": "no-store" } },
      );
    }

    const clientKey = requireRuntimeValue(
      environment === "sandbox"
        ? runtime.MIDTRANS_SNAP_SANDBOX_CLIENT_KEY
        : runtime.MIDTRANS_SNAP_PRODUCTION_CLIENT_KEY,
      environment === "sandbox"
        ? "MIDTRANS_SNAP_SANDBOX_CLIENT_KEY"
        : "MIDTRANS_SNAP_PRODUCTION_CLIENT_KEY",
    );

    const scriptUrl = requireRuntimeValue(
      environment === "sandbox"
        ? runtime.MIDTRANS_SNAP_SANDBOX_SCRIPT_URL
        : runtime.MIDTRANS_SNAP_PRODUCTION_SCRIPT_URL,
      environment === "sandbox"
        ? "MIDTRANS_SNAP_SANDBOX_SCRIPT_URL"
        : "MIDTRANS_SNAP_PRODUCTION_SCRIPT_URL",
    );

    return Response.json(
      {
        enabled: true,
        mode,
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
        error:
          error instanceof Error
            ? error.message
            : "Konfigurasi Midtrans belum lengkap.",
      },
      {
        status: 503,
        headers: { "Cache-Control": "no-store" },
      },
    );
  }
}
