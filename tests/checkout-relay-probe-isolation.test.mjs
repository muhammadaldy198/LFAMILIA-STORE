import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";
import test from "node:test";

const root = process.cwd();
const methods = fs.readFileSync(path.join(root, "app/api/payment-methods/route.ts"), "utf8");
const autoRoute = fs.readFileSync(path.join(root, "app/api/payments/auto/create/route.ts"), "utf8");
const router = fs.readFileSync(path.join(root, "lib/server/payment-gateway-router.ts"), "utf8");

test("storefront payment-method discovery does not depend on live relay probes", () => {
  assert.doesNotMatch(methods, /OperationalReadiness/);
  assert.match(methods, /getIpaymuReadiness\(\)/);
  assert.match(methods, /getMidtransReadiness\(\)/);
});

test("automatic checkout routing uses saved readiness, not live relay probes", () => {
  assert.doesNotMatch(autoRoute, /OperationalReadiness/);
  assert.doesNotMatch(router, /OperationalReadiness/);
  assert.match(router, /getIpaymuReadiness\(\)/);
  assert.match(router, /getMidtransReadiness\(\)/);
});
