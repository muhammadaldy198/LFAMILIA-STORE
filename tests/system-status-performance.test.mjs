import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";
import test from "node:test";

const root = path.resolve(import.meta.dirname, "..");
const read = (file) => fs.readFileSync(path.join(root, file), "utf8");

test("public system status does not run payment-profile repair work", () => {
  const route = read("app/api/system-status/route.ts");
  const paymentModes = read("lib/server/payment-mode-config.ts");

  assert.match(route, /getPaymentModeOverview\(\{ migrateObsoleteProfiles: false \}\)/);
  assert.match(paymentModes, /options\.migrateObsoleteProfiles !== false/);
});
