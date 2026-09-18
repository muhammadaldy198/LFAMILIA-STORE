import { createRemoteJWKSet, jwtVerify } from "jose";
import { getRuntimeEnv } from "@/lib/server/runtime-env";

type GoogleRuntime = {
  GOOGLE_OAUTH_CLIENT_ID?: string;
};

const GOOGLE_JWKS = createRemoteJWKSet(new URL("https://www.googleapis.com/oauth2/v3/certs"));

export function getGoogleIdentityClientId() {
  return getRuntimeEnv<GoogleRuntime>().GOOGLE_OAUTH_CLIENT_ID?.trim() ?? "";
}

export function googleIdentityConfigured() {
  return Boolean(getGoogleIdentityClientId());
}

export async function verifyGoogleIdentityCredential(credential: string) {
  const clientId = getGoogleIdentityClientId();
  if (!clientId) throw new Error("Login Google belum dikonfigurasi.");

  const verified = await jwtVerify(credential, GOOGLE_JWKS, {
    issuer: ["https://accounts.google.com", "accounts.google.com"],
    audience: clientId,
  });

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
