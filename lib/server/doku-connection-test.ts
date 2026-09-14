import { createPrivateKey, sign as rsaSign } from "node:crypto";
import { getRuntimeEnv, requireRuntimeValue } from "@/lib/server/runtime-env";

export type DokuTestEnvironment = "sandbox" | "production";

type DokuTestRuntime = {
  DOKU_SANDBOX_CLIENT_ID?: string;
  DOKU_SANDBOX_SECRET_KEY?: string;
  DOKU_SANDBOX_PRIVATE_KEY?: string;
  DOKU_SANDBOX_PRIVATE_KEY_PASSPHRASE?: string;
  DOKU_PRODUCTION_CLIENT_ID?: string;
  DOKU_PRODUCTION_SECRET_KEY?: string;
  DOKU_PRODUCTION_PRIVATE_KEY?: string;
  DOKU_PRODUCTION_PRIVATE_KEY_PASSPHRASE?: string;
};

type TokenResponse = {
  responseCode?: string;
  responseMessage?: string;
  accessToken?: string;
  tokenType?: string;
  expiresIn?: number;
};

function runtime() {
  return getRuntimeEnv<DokuTestRuntime>();
}

export function dokuApiOrigin(environment: DokuTestEnvironment) {
  return environment === "sandbox"
    ? "https://api-sandbox.doku.com"
    : "https://api.doku.com";
}

function credentials(environment: DokuTestEnvironment) {
  const source = runtime();
  const sandbox = environment === "sandbox";
  const prefix = sandbox ? "DOKU_SANDBOX" : "DOKU_PRODUCTION";
  return {
    clientId: requireRuntimeValue(
      sandbox ? source.DOKU_SANDBOX_CLIENT_ID : source.DOKU_PRODUCTION_CLIENT_ID,
      `${prefix}_CLIENT_ID`,
    ),
    secretKey: requireRuntimeValue(
      sandbox ? source.DOKU_SANDBOX_SECRET_KEY : source.DOKU_PRODUCTION_SECRET_KEY,
      `${prefix}_SECRET_KEY`,
    ),
    privateKey: requireRuntimeValue(
      sandbox ? source.DOKU_SANDBOX_PRIVATE_KEY : source.DOKU_PRODUCTION_PRIVATE_KEY,
      `${prefix}_PRIVATE_KEY`,
    ),
    privateKeyPassphrase: (
      sandbox
        ? source.DOKU_SANDBOX_PRIVATE_KEY_PASSPHRASE
        : source.DOKU_PRODUCTION_PRIVATE_KEY_PASSPHRASE
    )?.trim() || "",
  };
}

/**
 * Performs a real DOKU SNAP B2B token request only.
 * This validates the Client ID and RSA key pair against DOKU and also requires
 * Secret Key presence for subsequent SNAP HMAC requests. No payment, refund,
 * top-up, or fulfillment transaction is created.
 */
export async function testDokuB2BConnection(environment: DokuTestEnvironment) {
  const config = credentials(environment);
  const requestTimestamp = new Date().toISOString();
  let privateKey;
  try {
    privateKey = createPrivateKey({
      key: config.privateKey,
      format: "pem",
      passphrase: config.privateKeyPassphrase || undefined,
    });
  } catch {
    throw new Error(
      "RSA Private Key DOKU tidak valid atau passphrase private key tidak cocok.",
    );
  }

  const signature = rsaSign(
    "RSA-SHA256",
    Buffer.from(`${config.clientId}|${requestTimestamp}`, "utf8"),
    privateKey,
  ).toString("base64");

  const endpointPath = "/authorization/v1/access-token/b2b";
  const apiOrigin = dokuApiOrigin(environment);
  const response = await fetch(`${apiOrigin}${endpointPath}`, {
    method: "POST",
    headers: {
      "content-type": "application/json",
      accept: "application/json",
      "x-client-key": config.clientId,
      "x-timestamp": requestTimestamp,
      "x-signature": signature,
    },
    body: JSON.stringify({ grantType: "client_credentials" }),
    signal: AbortSignal.timeout(15_000),
  });

  const payload = (await response.json().catch(() => ({}))) as TokenResponse;
  if (!response.ok || !payload.accessToken?.trim()) {
    throw new Error(
      payload.responseMessage?.trim() ||
        `DOKU menolak tes B2B token (${response.status}).`,
    );
  }

  // Do not return the access token or any credential to the browser.
  void config.secretKey;
  return {
    ok: true as const,
    environment,
    apiOrigin,
    tokenType: payload.tokenType?.trim() || "Bearer",
    expiresIn: Math.max(Number(payload.expiresIn) || 900, 0),
  };
}
