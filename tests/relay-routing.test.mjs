import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";
import test from "node:test";

const root = process.cwd();
const read = (file) => fs.readFileSync(path.join(root, file), "utf8");

test("relay configuration is managed from encrypted admin profile", () => {
  const integration = read("lib/server/integration-config.ts");
  assert.match(integration, /"relay:service": \["digiflazzOrigin", "ipaymuOrigin", "bisnapOrigin", "hosts", "token"\]/);
  assert.match(integration, /PROVIDER_RELAY_DIGIFLAZZ_ORIGIN/);
  assert.match(integration, /PROVIDER_RELAY_IPAYMU_ORIGIN/);
  assert.match(integration, /PROVIDER_RELAY_MIDTRANS_BISNAP_ORIGIN/);
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
    "lib/server/midtrans-bisnap.ts",
  ]) {
    const source = read(file);
    assert.match(source, /providerRelayRequest\(/, file);
    assert.doesNotMatch(source, /withProviderRelayHeaders\(/, file);
  }
});

test("admin exposes separate encrypted relay URLs and token", () => {
  const source = read("components/admin-integration-manager.tsx");
  for (const field of ["digiflazzOrigin", "ipaymuOrigin", "bisnapOrigin", "token"]) {
    assert.ok(source.includes(`key: "${field}"`), field);
  }
  assert.match(source, /tidak perlu membuat PROVIDER_RELAY_\* di Cloudflare/);
});
