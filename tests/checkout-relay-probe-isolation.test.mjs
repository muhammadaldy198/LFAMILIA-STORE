import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";
import test from "node:test";

const root = process.cwd();
const methods = fs.readFileSync(path.join(root, "app/api/payment-methods/route.ts"), "utf8");
const autoRoute = fs.readFileSync(path.join(root, "app/api/payments/auto/create/route.ts"), "utf8");

for (const [name, source] of [["payment methods", methods], ["auto route", autoRoute]]) {
  test(`${name} does not depend on live relay probes`, () => {
    assert.doesNotMatch(source, /OperationalReadiness/);
    assert.match(source, /getIpaymuReadiness\(\)/);
    assert.match(source, /getMidtransReadiness\(\)/);
  });
}
