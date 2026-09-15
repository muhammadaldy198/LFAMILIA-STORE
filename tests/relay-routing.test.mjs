import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";
import test from "node:test";

const root = process.cwd();
const read = (file) => fs.readFileSync(path.join(root, file), "utf8");

test("relay configuration is encrypted and supports DigiFlazz, Midtrans, and Melostore egress", () => {
  const integration = read("lib/server/integration-config.ts");
  assert.match(integration, /"relay:service": \["digiflazzOrigin", "midtransOrigin", "hosts", "token"\]/);
  assert.match(integration, /PROVIDER_RELAY_DIGIFLAZZ_ORIGIN/);
  assert.match(integration, /PROVIDER_RELAY_MIDTRANS_ORIGIN/);
  assert.match(integration, /PROVIDER_RELAY_HOSTS/);
  assert.doesNotMatch(integration, /IPAYMU|VIPPAYMENT/i);
});

test("provider relay rewrites destination URL and adds authenticated environment headers", () => {
  const relay = read("lib/server/provider-relay.ts");
  assert.match(relay, /relay\.pathname = source\.pathname/);
  assert.match(relay, /x-lfamilia-relay-token/);
  assert.match(relay, /x-lfamilia-\$\{route\.provider\}-environment/);
  assert.match(relay, /RelayProvider = "digiflazz" \| "midtrans" \| "melostore"/);
});

test("provider integrations that require static egress use providerRelayRequest", () => {
  for (const file of [
    "lib/server/providers/digiflazz.ts",
    "lib/server/digiflazz-pricing.ts",
    "lib/server/midtrans.ts",
    "lib/server/nickname-check.ts",
  ]) {
    const source = read(file);
    assert.match(source, /providerRelayRequest\(/, file);
  }
});

test("admin exposes relay configuration while payment credentials stay outside VPS config", () => {
  const source = read("components/admin-integration-workspace.tsx");
  assert.match(source, /Digiflazz Relay URL/);
  assert.match(source, /Midtrans Relay URL/);
  assert.match(source, /Relay Token/);
  assert.match(source, /Host yang diizinkan/);
  assert.match(source, /VPS tidak menyimpan Client Secret atau private key/);
});

test("relay service allowlists Midtrans BI-SNAP and Melostore nickname outbound paths", () => {
  const server = read("relay/server.mjs");
  assert.match(server, /name: "digiflazz"/);
  assert.match(server, /name: "midtrans"/);
  assert.match(server, /name: "melostore"/);
  assert.match(server, /\/v1\.0\/access-token\/b2b/);
  assert.match(server, /\/v1\.0\/transfer-va\/create-va/);
  assert.match(server, /\/api\/v1\/h2h\/check-nickname/);
  assert.match(server, /midtransAllowedPaths/);
  assert.match(server, /melostoreAllowedPaths/);
  assert.doesNotMatch(server, /MIDTRANS_CLIENT_SECRET|MIDTRANS_PRIVATE_KEY|MIDTRANS_CLIENT_ID/);
});
