import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";
import test from "node:test";

const root = process.cwd();
const read = (file) => fs.readFileSync(path.join(root, file), "utf8");

test("relay configuration is encrypted and DigiFlazz-only", () => {
  const integration = read("lib/server/integration-config.ts");
  assert.match(integration, /"relay:service": \["digiflazzOrigin", "hosts", "token"\]/);
  assert.match(integration, /PROVIDER_RELAY_DIGIFLAZZ_ORIGIN/);
  assert.doesNotMatch(integration, /IPAYMU|MIDTRANS|VIPPAYMENT/i);
});

test("provider relay rewrites destination URL and adds authenticated environment headers", () => {
  const relay = read("lib/server/provider-relay.ts");
  assert.match(relay, /relay\.pathname = source\.pathname/);
  assert.match(relay, /x-lfamilia-relay-token/);
  assert.match(relay, /x-lfamilia-\$\{route\.provider\}-environment/);
  assert.match(relay, /RelayProvider = "digiflazz"/);
});

test("DigiFlazz integrations use providerRelayRequest", () => {
  for (const file of ["lib/server/providers/digiflazz.ts", "lib/server/digiflazz-pricing.ts"]) {
    const source = read(file);
    assert.match(source, /providerRelayRequest\(/, file);
  }
});

test("admin exposes only DigiFlazz relay URL and token", () => {
  const source = read("components/admin-integration-workspace.tsx");
  assert.match(source, /Relay URL/);
  assert.match(source, /Relay Token/);
  assert.doesNotMatch(source, /ipaymuOrigin|iPaymu/i);
});

test("relay service contains no legacy payment gateway upstream", () => {
  const server = read("relay/server.mjs");
  assert.match(server, /name: "digiflazz"/);
  assert.doesNotMatch(server, /ipaymu|midtrans|bisnap/i);
});
