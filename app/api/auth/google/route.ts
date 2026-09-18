import { allowRequest } from "@/lib/server/security";
import { buildGoogleAuthorizationUrl, googleOAuthConfigured } from "@/lib/server/google-oauth";

const STATE_COOKIE = "lf_google_state";
const NONCE_COOKIE = "lf_google_nonce";
const RETURN_COOKIE = "lf_google_return";
const OAUTH_MAX_AGE = 600;

function randomValue(bytes = 24) {
  const data = crypto.getRandomValues(new Uint8Array(bytes));
  return btoa(String.fromCharCode(...data))
    .replaceAll("+", "-")
    .replaceAll("/", "_")
    .replace(/=+$/g, "");
}

function safeReturnTo(value: string | null) {
  if (!value || !value.startsWith("/") || value.startsWith("//")) return "/account";
  try {
    const parsed = new URL(value, "https://local.invalid");
    if (parsed.origin !== "https://local.invalid") return "/account";
    return `${parsed.pathname}${parsed.search}${parsed.hash}`;
  } catch {
    return "/account";
  }
}

function oauthCookie(name: string, value: string) {
  return `${name}=${encodeURIComponent(value)}; Path=/api/auth/google; HttpOnly; Secure; SameSite=Lax; Max-Age=${OAUTH_MAX_AGE}`;
}

export async function GET(request: Request) {
  const rate = await allowRequest(request, "customer-google-oauth-start", 20, 3600);
  if (!rate.allowed) {
    return Response.json(
      { error: "Terlalu banyak percobaan login. Coba lagi nanti." },
      { status: 429, headers: { "Retry-After": String(rate.retryAfter), "Cache-Control": "no-store" } },
    );
  }
  if (!googleOAuthConfigured()) {
    return Response.redirect(new URL("/account?auth=google-unavailable", request.url), 303);
  }

  const url = new URL(request.url);
  const state = randomValue();
  const nonce = randomValue();
  const returnTo = safeReturnTo(url.searchParams.get("returnTo"));
  const headers = new Headers({
    Location: buildGoogleAuthorizationUrl(state, nonce),
    "Cache-Control": "no-store",
  });
  headers.append("Set-Cookie", oauthCookie(STATE_COOKIE, state));
  headers.append("Set-Cookie", oauthCookie(NONCE_COOKIE, nonce));
  headers.append("Set-Cookie", oauthCookie(RETURN_COOKIE, returnTo));
  return new Response(null, { status: 303, headers });
}
