import { createRemoteJWKSet, jwtVerify } from "jose";
import { getPublicBaseUrl, getRuntimeEnv } from "@/lib/server/runtime-env";

type GoogleRuntime = {
  GOOGLE_OAUTH_CLIENT_ID?: string;
  GOOGLE_OAUTH_CLIENT_SECRET?: string;
};

type TokenResponse = {
  id_token?: string;
  error?: string;
  error_description?: string;
};

const GOOGLE_AUTHORIZATION_URL = "https://accounts.google.com/o/oauth2/v2/auth";
const GOOGLE_TOKEN_URL = "https://oauth2.googleapis.com/token";
const GOOGLE_JWKS = createRemoteJWKSet(new URL("https://www.googleapis.com/oauth2/v3/certs"));

function runtimeCredentials() {
  const runtime = getRuntimeEnv<GoogleRuntime>();
  return {
    clientId: runtime.GOOGLE_OAUTH_CLIENT_ID?.trim() ?? "",
    clientSecret: runtime.GOOGLE_OAUTH_CLIENT_SECRET?.trim() ?? "",
  };
}

export function googleOAuthConfigured() {
  const { clientId, clientSecret } = runtimeCredentials();
  if (!clientId || !clientSecret) return false;
  try {
    return Boolean(getPublicBaseUrl());
  } catch {
    return false;
  }
}

export function requireGoogleOAuthConfig() {
  const credentials = runtimeCredentials();
  if (!credentials.clientId || !credentials.clientSecret) {
    throw new Error("Login Google belum dikonfigurasi.");
  }
  return {
    ...credentials,
    redirectUri: `${getPublicBaseUrl()}/api/auth/google/callback`,
  };
}

export function buildGoogleAuthorizationUrl(state: string, nonce: string) {
  const { clientId, redirectUri } = requireGoogleOAuthConfig();
  const url = new URL(GOOGLE_AUTHORIZATION_URL);
  url.searchParams.set("client_id", clientId);
  url.searchParams.set("redirect_uri", redirectUri);
  url.searchParams.set("response_type", "code");
  url.searchParams.set("scope", "openid email profile");
  url.searchParams.set("state", state);
  url.searchParams.set("nonce", nonce);
  url.searchParams.set("prompt", "select_account");
  return url.toString();
}

export async function exchangeGoogleAuthorizationCode(code: string, expectedNonce: string) {
  const { clientId, clientSecret, redirectUri } = requireGoogleOAuthConfig();
  const response = await fetch(GOOGLE_TOKEN_URL, {
    method: "POST",
    headers: { "content-type": "application/x-www-form-urlencoded" },
    body: new URLSearchParams({
      code,
      client_id: clientId,
      client_secret: clientSecret,
      redirect_uri: redirectUri,
      grant_type: "authorization_code",
    }),
  });
  const payload = await response.json().catch(() => ({})) as TokenResponse;
  if (!response.ok || !payload.id_token) {
    throw new Error(payload.error_description || payload.error || "Google gagal menukar authorization code.");
  }

  const verified = await jwtVerify(payload.id_token, GOOGLE_JWKS, {
    issuer: ["https://accounts.google.com", "accounts.google.com"],
    audience: clientId,
  });
  if (verified.payload.nonce !== expectedNonce) {
    throw new Error("Nonce Google OAuth tidak valid.");
  }

  const subject = typeof verified.payload.sub === "string" ? verified.payload.sub : "";
  const email = typeof verified.payload.email === "string" ? verified.payload.email.trim().toLowerCase() : "";
  const emailVerified = verified.payload.email_verified === true;
  const name = typeof verified.payload.name === "string" ? verified.payload.name.trim() : "";
  const picture = typeof verified.payload.picture === "string" ? verified.payload.picture.trim() : "";

  if (!subject || !email || !emailVerified) {
    throw new Error("Akun Google tidak menyediakan email terverifikasi.");
  }

  return {
    subject,
    email,
    name: name || email.split("@")[0] || "Pelanggan",
    picture,
  };
}
