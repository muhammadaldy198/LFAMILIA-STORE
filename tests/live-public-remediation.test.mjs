import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";
import test from "node:test";
import { pathToFileURL } from "node:url";

const root = process.cwd();

const { neutralizePublicCopy } = await import(
  `${pathToFileURL(path.join(root, "lib/public-copy.ts")).href}?test=${Date.now()}`
);

test("customer-facing copy removes integration brand names without touching ordinary copy", () => {
  const input = "VA melalui Midtrans, QRIS DOKU, produk Digiflazz, validasi Melostore/KokinPay. LFAMILIA tetap tampil.";
  const output = neutralizePublicCopy(input);
  assert.doesNotMatch(output, /doku|midtrans|digiflazz|melostore|kokinpay/i);
  assert.match(output, /sistem LFAMILIA/);
  assert.match(output, /LFAMILIA tetap tampil/);
  assert.equal(neutralizePublicCopy("Pembayaran aman di LFAMILIA STORE."), "Pembayaran aman di LFAMILIA STORE.");
});

test("public storefront and payment settings sanitize persisted customer copy", () => {
  const storefront = fs.readFileSync(path.join(root, "app/api/storefront/route.ts"), "utf8");
  const payment = fs.readFileSync(path.join(root, "app/api/payment-page-settings/route.ts"), "utf8");

  assert.match(storefront, /neutralizePublicCopy\(row\.question\)/);
  assert.match(storefront, /neutralizePublicCopy\(row\.answer\)/);
  assert.match(storefront, /neutralizeStorefrontSettings\(settings\)/);
  assert.match(payment, /subtitle: neutralizePublicCopy\(settings\.subtitle\)/);
  assert.match(payment, /supportText: neutralizePublicCopy\(settings\.supportText\)/);

  // URLs and non-copy values must not be rewritten by the brand neutralizer.
  assert.doesNotMatch(payment, /neutralizePublicCopy\(settings\.(?:headerImageUrl|supportUrl|accentColor)\)/);
  assert.doesNotMatch(storefront, /neutralizePublicCopy\(settings\.(?:logoUrl|bannerImageUrl|bannerCtaHref|instagramUrl|discordUrl|supportWhatsapp|supportEmail)\)/);
});

test("completed D1 runtime repair exits through one batched preflight before legacy repair loop", () => {
  const source = fs.readFileSync(path.join(root, "lib/server/database-repair.ts"), "utf8");
  const fastPath = source.indexOf("if (await runtimeRepairAlreadyComplete(db)) return;");
  const repairLoop = source.indexOf("for (const [table, column, definition] of columns)");

  assert.ok(fastPath >= 0, "missing completed-repair fast path");
  assert.ok(repairLoop > fastPath, "fast path must run before the expensive repair loop");
  assert.match(source, /await db\.batch\(\[/);
  assert.match(source, /SELECT name FROM d1_migrations WHERE name = \? LIMIT 1/);
  assert.match(source, /package_tabs_enabled/);
  assert.match(source, /package_group/);
  assert.match(source, /support_widget_enabled/);
  assert.match(source, /supplier_cost_snapshot/);
  assert.match(source, /promotion_reservation_released/);
  assert.match(source, /FINAL_SCHEMA_OBJECTS\.every/);
});
