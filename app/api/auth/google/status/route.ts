import { googleOAuthConfigured } from "@/lib/server/google-oauth";

export async function GET() {
  return Response.json(
    { enabled: googleOAuthConfigured() },
    { headers: { "Cache-Control": "no-store" } },
  );
}
