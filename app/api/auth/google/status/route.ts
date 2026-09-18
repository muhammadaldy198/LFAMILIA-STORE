import { getGoogleIdentityClientId, googleIdentityConfigured } from "@/lib/server/google-oauth";

export async function GET() {
  const enabled = googleIdentityConfigured();
  return Response.json(
    { enabled, clientId: enabled ? getGoogleIdentityClientId() : null },
    { headers: { "Cache-Control": "no-store" } },
  );
}
