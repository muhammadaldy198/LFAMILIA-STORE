import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import test from "node:test";

const source = async (path) => readFile(new URL(`../${path}`, import.meta.url), "utf8");

test("external checkout reserves a promotion before DOKU instructions and releases failure", async () => {
  const route = await source("app/api/payments/auto/create/route.ts");
  const reservations = await source("lib/server/promotions.ts");
  assert.match(route, /reserveExternalPromotion\(/);
  assert.match(route, /releaseExternalPromotion\(orderId\)/);
  assert.match(reservations, /promotion_reservations/);
  assert.match(reservations, /status = 'consumed'/);
});

test("order delivery and supplier accounting are snapshots, not current category lookups", async () => {
  const orders = await source("lib/server/orders.ts");
  const adminOrders = await source("app/api/admin/orders/route.ts");
  assert.match(orders, /delivery_mode/);
  assert.match(orders, /supplier_cost_snapshot/);
  assert.match(adminOrders, /order\.delivery_mode/);
  assert.doesNotMatch(adminOrders, /LOWER\(TRIM\(category\)\)/);
});

test("staff API contract masks event payloads and financial/provider fields", async () => {
  const route = await source("app/api/admin/orders/route.ts");
  const summary = await source("app/api/admin/summary/route.ts");
  assert.match(route, /access\.role === "staff"[\s\S]*eventRows\.results\.map/);
  assert.match(route, /source: "system"/);
  const staffEvents = route.slice(route.indexOf('events: access.role === "staff"'), route.indexOf('role: access.role'));
  assert.doesNotMatch(staffEvents, /event_id|payload_json|provider/);
  assert.match(route, /total: null/);
  assert.match(summary, /recentActivities: access\.role === "staff" \? \[\]/);
});

test("DOKU callback binds signature validation to one transaction environment and acknowledges ignored signed callbacks", async () => {
  const doku = await source("lib/server/doku.ts");
  const callback = await source("app/api/payments/doku/callback/route.ts");
  assert.match(doku, /expectedEnvironment/);
  assert.doesNotMatch(doku, /for \(const environment of \["sandbox", "production"\]/);
  assert.match(callback, /expectedOrder\?\.doku_environment \?\? expectedWallet\?\.doku_environment/);
  assert.match(callback, /expectedEnvironment,/);
  assert.match(callback, /if \(!order\) return notificationResponse/);
});

test("public product and wallet contracts are neutral and non-cacheable", async () => {
  const products = await source("app/api/products/route.ts");
  const wallet = await source("app/api/wallet/route.ts");
  assert.match(products, /fulfillmentAvailable/);
  assert.match(products, /Cache-Control": "no-store/);
  assert.doesNotMatch(products, /providerCode:/);
  assert.doesNotMatch(products, /providerSku:/);
  assert.doesNotMatch(wallet, /DOKU/);
});
