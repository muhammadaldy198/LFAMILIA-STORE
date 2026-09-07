import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";
import test from "node:test";

const root = process.cwd();
const methods = fs.readFileSync(path.join(root, "app/api/payment-methods/route.ts"), "utf8");
const autoRoute = fs.readFileSync(path.join(root, "app/api/payments/auto/create/route.ts"), "utf8");
const doku = fs.readFileSync(path.join(root, "lib/server/doku.ts"), "utf8");

test("DOKU storefront discovery does not depend on VPS relay probes", () => {
  assert.match(methods, /getDokuReadiness\(\)/);
  assert.match(autoRoute, /getDokuReadiness\(\)/);
  assert.doesNotMatch(methods, /providerRelay|probeProviderRelay/);
  assert.doesNotMatch(autoRoute, /providerRelay|probeProviderRelay/);
  assert.doesNotMatch(doku, /providerRelay|probeProviderRelay/);
});
