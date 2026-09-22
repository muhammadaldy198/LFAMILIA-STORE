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
  assert.match(route, /readStorefrontSettings\(\{ repairSchema: false \}\)/);
  assert.match(route, /public, max-age=15, s-maxage=15, stale-while-revalidate=30/);
  assert.match(paymentModes, /options\.migrateObsoleteProfiles !== false/);
});


test("public catalog avoids one-time repair work and permits short client caching", () => {
  const route = read("app/api/products/route.ts");
  const hook = read("hooks/use-store-products.ts");
  const products = read("lib/server/products.ts");
  const availability = read("lib/server/availability.ts");

  assert.doesNotMatch(route, /ensureKokinpayNicknameGameCodeBackfill/);
  assert.match(route, /readProducts\(false, \{ repairSchema: false \}\)/);
  assert.match(route, /readDigiflazzPackageAvailability\(\{ repairSchema: false \}\)/);
  assert.match(products, /options\.repairSchema !== false/);
  assert.match(availability, /options\.repairSchema !== false/);
  assert.doesNotMatch(hook, /cache: "no-store"/);
  assert.match(route, /public, max-age=10, s-maxage=10, stale-while-revalidate=20/);
});
