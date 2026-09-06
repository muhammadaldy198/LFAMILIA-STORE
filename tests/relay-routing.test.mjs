import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";
import test from "node:test";

const root = process.cwd();
const read = (file) => fs.readFileSync(path.join(root, file), "utf8");

test("relay configuration is managed from encrypted admin profile", () => {
  const integration = read("lib/server/integration-config.ts");
  assert.match(integration, /"relay:service": \["digiflazzOrigin", "ipaymuOrigin", "hosts", "token"\]/);
  assert.match(integration, /PROVIDER_RELAY_DIGIFLAZZ_ORIGIN/);
  assert.match(integration, /PROVIDER_RELAY_IPAYMU_ORIGIN/);
});

test("provider relay rewrites destination URL and adds authenticated environment headers", () => {
  const relay = read("lib/server/provider-relay.ts");
  assert.match(relay, /relay\.pathname = source\.pathname/);
  assert.match(relay, /x-lfamilia-relay-token/);
  assert.match(relay, /x-lfamilia-\$\{route\.provider\}-environment/);
});

test("relay-enabled providers use providerRelayRequest", () => {
  for (const file of [
    "lib/server/providers/digiflazz.ts",
    "lib/server/digiflazz-pricing.ts",
    "lib/server/ipaymu.ts",
  ]) {
    const source = read(file);
    assert.match(source, /providerRelayRequest\(/, file);
  }
});

test("admin exposes encrypted DigiFlazz/iPaymu relay URLs and token", () => {
  const source = read("components/admin-integration-manager.tsx");
  for (const field of ["digiflazzOrigin", "ipaymuOrigin", "token"]) {
    assert.ok(source.includes(`key: "${field}"`), field);
  }
  assert.doesNotMatch(source, /bisnapOrigin/i);
});

test("relay probe verifies health and token without provider transaction", () => {
  const relay = read("lib/server/provider-relay.ts");
  assert.match(relay, /new URL\("\/health"/);
  assert.match(relay, /method: "HEAD"/);
  assert.match(relay, /authResponse\.status === 405/);
});

test("admin relay view renders only active provider statuses", () => {
  const source = read("components/admin-integration-manager.tsx");
  assert.match(source, /Tes Koneksi Relay/);
  assert.match(source, /DigiFlazz/);
  assert.match(source, /iPaymu/);
  assert.doesNotMatch(source, /Midtrans/i);
});
