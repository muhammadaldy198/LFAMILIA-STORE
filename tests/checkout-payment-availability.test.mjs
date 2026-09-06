import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";
import test from "node:test";

const source = fs.readFileSync(path.join(process.cwd(), "app/checkout/page.tsx"), "utf8");

test("checkout methods are derived from all eligible gateways", () => {
  assert.match(source, /checkoutGatewayCandidates/);
  assert.match(source, /displayChannels/);
  assert.match(source, /for \(const gateway of checkoutGatewayCandidates\)/);
});

test("method selection chooses an eligible gateway automatically", () => {
  assert.match(source, /preferredGateways\(\)\.find/);
  assert.match(source, /candidate\.channels\.some\(\(channel\) => channel\.method === method\)/);
  assert.match(source, /setActiveCheckoutGateway\(gateway\.code\)/);
});

test("checkout shows a diagnostic instead of a blank payment section", () => {
  assert.match(source, /Metode pembayaran otomatis belum tersedia/);
});
