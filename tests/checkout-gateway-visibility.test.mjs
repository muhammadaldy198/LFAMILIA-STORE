import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";
import test from "node:test";

const source = fs.readFileSync(path.join(process.cwd(), "app/checkout/page.tsx"), "utf8");

test("checkout hides gateway/provider selector from customers", () => {
  assert.doesNotMatch(source, /Pilih gateway pembayaran/);
  assert.doesNotMatch(source, /gatewayOptions\.map\(\(gateway\)/);
  assert.match(source, /Pilih metode pembayaran yang ingin digunakan/);
});

test("automatic gateway routing remains available internally", () => {
  assert.match(source, /eligibleGatewayOptions/);
  assert.match(source, /activeCheckoutGateway/);
  assert.match(source, /gateway\.code === "midtrans"/);
});
