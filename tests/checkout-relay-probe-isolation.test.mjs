import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";
import test from "node:test";

const root = process.cwd();
const methods = fs.readFileSync(path.join(root, "app/api/payment-methods/route.ts"), "utf8");

test("checkout availability does not depend on live relay probes", () => {
  assert.doesNotMatch(methods, /OperationalReadiness/);
  assert.match(methods, /getIpaymuReadiness\(\)/);
});
