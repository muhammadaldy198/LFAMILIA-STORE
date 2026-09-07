import assert from "node:assert/strict";
import test, { after } from "node:test";
import { fileURLToPath } from "node:url";

import { createServer } from "vite";

const root = fileURLToPath(new URL("..", import.meta.url));
const vite = await createServer({
  appType: "custom",
  configFile: false,
  root,
  resolve: { alias: { "@": root } },
  server: { middlewareMode: true },
});

after(async () => {
  await vite.close();
});

const { verifyCloudflareAccess } = await vite.ssrLoadModule(
  "/lib/server/cloudflare-access.ts",
);

const encoder = new TextEncoder();
const teamDomain = "https://lfamilia-test.cloudflareaccess.com";
const audience = "lfamilia-admin-audience";
const kid = "test-signing-key";

function encode(value) {
  return Buffer.from(
    typeof value === "string" ? value : JSON.stringify(value),
  ).toString("base64url");
}

async function signToken(privateKey, claims) {
  const header = encode({ alg: "RS256", kid, typ: "JWT" });
  const payload = encode(claims);
  const signingInput = `${header}.${payload}`;
  const signature = await crypto.subtle.sign(
    "RSASSA-PKCS1-v1_5",
    privateKey,
    encoder.encode(signingInput),
  );
  return `${signingInput}.${Buffer.from(signature).toString("base64url")}`;
}

const keyPair = await crypto.subtle.generateKey(
  {
    name: "RSASSA-PKCS1-v1_5",
    modulusLength: 2048,
    publicExponent: new Uint8Array([1, 0, 1]),
    hash: "SHA-256",
  },
  true,
  ["sign", "verify"],
);
const publicJwk = await crypto.subtle.exportKey("jwk", keyPair.publicKey);
publicJwk.kid = kid;
publicJwk.alg = "RS256";
publicJwk.use = "sig";

const fetchJwks = async () => Response.json({ keys: [publicJwk] });
const env = { TEAM_DOMAIN: teamDomain, POLICY_AUD: audience };
const now = Math.floor(Date.now() / 1000);

function requestWith(token) {
  return new Request("https://lfamiliastore.my.id/admin/panel", {
    headers: {
      "cf-access-authenticated-user-email": "forged@example.com",
      "cf-access-jwt-assertion": token,
    },
  });
}

test("accepts a correctly signed Access JWT and trusts its email claim", async () => {
  const token = await signToken(keyPair.privateKey, {
    iss: teamDomain,
    aud: [audience],
    email: "Owner@Example.com",
    iat: now,
    nbf: now - 1,
    exp: now + 300,
  });

  assert.deepEqual(
    await verifyCloudflareAccess(requestWith(token), env, fetchJwks),
    { email: "owner@example.com" },
  );
});

test("rejects wrong audience, expired tokens, and invalid signatures", async () => {
  const wrongAudience = await signToken(keyPair.privateKey, {
    iss: teamDomain,
    aud: ["another-application"],
    email: "owner@example.com",
    iat: now,
    exp: now + 300,
  });
  assert.equal(
    await verifyCloudflareAccess(
      requestWith(wrongAudience),
      env,
      fetchJwks,
    ),
    null,
  );

  const expired = await signToken(keyPair.privateKey, {
    iss: teamDomain,
    aud: [audience],
    email: "owner@example.com",
    iat: now - 600,
    exp: now - 120,
  });
  assert.equal(
    await verifyCloudflareAccess(requestWith(expired), env, fetchJwks),
    null,
  );

  const otherPair = await crypto.subtle.generateKey(
    {
      name: "RSASSA-PKCS1-v1_5",
      modulusLength: 2048,
      publicExponent: new Uint8Array([1, 0, 1]),
      hash: "SHA-256",
    },
    true,
    ["sign", "verify"],
  );
  const invalidSignature = await signToken(otherPair.privateKey, {
    iss: teamDomain,
    aud: [audience],
    email: "owner@example.com",
    iat: now,
    exp: now + 300,
  });
  assert.equal(
    await verifyCloudflareAccess(
      requestWith(invalidSignature),
      env,
      fetchJwks,
    ),
    null,
  );
});
