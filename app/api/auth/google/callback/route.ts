import {
  clearCustomerSessionCookie,
  customerSessionCookie,
  loginOrRegisterGoogleCustomer,
} from "@/lib/server/customer-auth";
import { exchangeGoogleAuthorizationCode } from "@/lib/server/google-oauth";
import { allowRequest } from "@/lib/server/security";

const STATE_COOKIE = "lf_google_state";
const NONCE_COOKIE = "lf_google_nonce";
const RETURN_COOKIE = "lf_google_return";

function cookieValue(request: Request, name: string) {
  const cookie = request.headers.get("cookie") ?? "";
  for (const part of cookie.split(";")) {
    const [key, ...value] = part.trim().split("=");
    if (key === name) return decodeURIComponent(value.join("="));
  }
  return "";
}

function clearOauthCookie(name: string) {
  return `${name}=; Path=/api/auth/google; HttpOnly; Secure; SameSite=Lax; Max-Age=0`;
}

function safeReturnTo(value: string) {
  if (!value || !value.startsWith("/") || value.startsWith("//")) return "/account";
  try {
    const parsed = new URL(value, "https://local.invalid");
    if (parsed.origin !== "https://local.invalid") return "/account";
    return `${parsed.pathname}${parsed.search}${parsed.hash}`;
  } catch {
    return "/account";
  }
}

function redirect(request: Request, path: string, sessionCookie?: string) {
  const headers = new Headers({
    Location: new URL(path, request.url).toString(),
    "Cache-Control": "no-store",
  });
  if (sessionCookie) headers.append("Set-Cookie", sessionCookie);
  headers.append("Set-Cookie", clearOauthCookie(STATE_COOKIE));
  headers.append("Set-Cookie", clearOauthCookie(NONCE_COOKIE));
  headers.append("Set-Cookie", clearOauthCookie(RETURN_COOKIE));
  return new Response(null, { status: 303, headers });
}

export async function GET(request: Request) {
  const rate = await allowRequest(request, "customer-google-oauth-callback", 30, 3600);
  if (!rate.allowed) return redirect(request, "/account?auth=google-error");

  const url = new URL(request.url);
  if (url.searchParams.get("error")) {
    return redirect(request, "/account?auth=google-cancelled");
  }

  const state = url.searchParams.get("state") ?? "";
  const code = url.searchParams.get("code") ?? "";
  const expectedState = cookieValue(request, STATE_COOKIE);
  const nonce = cookieValue(request, NONCE_COOKIE);
  const returnTo = safeReturnTo(cookieValue(request, RETURN_COOKIE));

  if (!state || !code || !expectedState || !nonce || state !== expectedState) {
    return redirect(request, "/account?auth=google-error");
  }

  try {
    const identity = await exchangeGoogleAuthorizationCode(code, nonce);
    const session = await loginOrRegisterGoogleCustomer(identity);
    return redirect(
      request,
      returnTo,
      customerSessionCookie(session.token, session.expiresAt),
    );
  } catch {
    return redirect(request, "/account?auth=google-error", clearCustomerSessionCookie());
  }
}
