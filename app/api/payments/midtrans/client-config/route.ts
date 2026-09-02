import { getRuntimeEnv } from "@/lib/server/runtime-env";

export const dynamic = "force-dynamic";

type RuntimeEnv = {
  MIDTRANS_ENV?: string;
  MIDTRANS_CLIENT_KEY?: string;
};

export async function GET() {
  const runtime = getRuntimeEnv<RuntimeEnv>();
  const environment =
    runtime.MIDTRANS_ENV?.trim().toLowerCase() === "production"
      ? "production"
      : "sandbox";
  const clientKey = runtime.MIDTRANS_CLIENT_KEY?.trim() || null;

  return Response.json(
    {
      enabled: Boolean(clientKey),
      environment,
      clientKey,
      scriptUrl:
        environment === "production"
          ? "https://app.midtrans.com/snap/snap.js"
          : "https://app.sandbox.midtrans.com/snap/snap.js",
    },
    { headers: { "Cache-Control": "no-store" } },
  );
}
