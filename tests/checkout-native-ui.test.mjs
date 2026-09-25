import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";
import test from "node:test";

const root = process.cwd();
const read = (file) => fs.readFileSync(path.join(root, file), "utf8");

test("checkout visual tuning is static and observer-free", () => {
  const checkout = read("app/checkout/page.tsx");
  const layout = read("app/checkout/layout.tsx");

  for (const marker of [
    "data-lf-checkout-banner",
    "data-lf-product-hero",
    "data-lf-product-art",
    "data-lf-product-info",
    "data-lf-product-features",
    "data-lf-account-grid",
  ]) {
    assert.match(checkout, new RegExp(marker));
    assert.match(layout, new RegExp(marker));
  }

  assert.doesNotMatch(layout, /CheckoutUiEnhancer|CheckoutSpacingTuning/);
  assert.equal(fs.existsSync(path.join(root, "components/checkout-ui-enhancer.tsx")), false);
  assert.equal(fs.existsSync(path.join(root, "components/checkout-spacing-tuning.tsx")), false);
});

test("WhatsApp input is normalized in React", () => {
  const checkout = read("app/checkout/page.tsx");
  assert.match(checkout, /function normalizeWhatsapp/);
  assert.match(checkout, /setContact\(normalizeWhatsapp\(event\.target\.value\)\)/);
  assert.match(checkout, /maxLength=\{17\}/);
  assert.match(checkout, /autoComplete="tel"/);
});

test("nickname verification follows the product configuration and blocks checkout until verified", () => {
  const checkout = read("app/checkout/page.tsx");
  assert.match(checkout, /Boolean\(product\.nicknameRequired\)/);
  assert.match(checkout, /const canCheckNickname = nicknameRequired/);
  assert.match(checkout, /visibleNickname\.status !== "success"/);
  assert.match(checkout, /blocking=\{nicknameRequired\}/);
});

test("customer checkout remains gateway-neutral under dual routing", () => {
  const checkout = read("app/checkout/page.tsx");
  assert.doesNotMatch(checkout, /code: "doku"|code: "midtrans"/i);
  assert.doesNotMatch(checkout, /"DOKU Checkout"|"Midtrans BI-SNAP"|Pesanan diteruskan otomatis ke provider|Pembayaran melalui gateway/);
  assert.doesNotMatch(checkout, /Pilih gateway pembayaran/);
});


test("checkout desktop banner bleeds edge to edge across the viewport", () => {
  const layout = read("app/checkout/layout.tsx");

  assert.match(layout, /@media \(min-width: 1024px\)/);
  assert.match(layout, /\[data-lf-checkout-banner="true"\][\s\S]*width: 100vw !important/);
  assert.match(layout, /margin-left: calc\(50% - 50vw\) !important/);
  assert.match(layout, /margin-right: calc\(50% - 50vw\) !important/);
});

test("checkout nominal labels strip a direct product-name prefix", () => {
  const checkout = read("app/checkout/page.tsx");
  assert.match(checkout, /lowerLabel\.startsWith\(lowerProductName\)/);
  assert.match(checkout, /const boundary = cleanLabel\.slice/);
  assert.match(checkout, /const directLabel = cleanLabel/);
  assert.match(checkout, /trimStart\(\)/);
});

test("checkout mobile controls stay compact and scoped", () => {
  const checkout = read("app/checkout/page.tsx");
  const css = read("app/globals.css");

  assert.match(checkout, /data-lf-checkout-form="true"/);
  assert.match(checkout, /data-lf-mobile-checkout="true"/);
  assert.match(checkout, /size="sm"[\s\S]*Gunakan/);
  assert.match(checkout, /size="sm"[\s\S]*Pakai Voucher Yang Tersedia/);
  assert.match(css, /Checkout mobile density/);
  assert.match(css, /height: 1\\.9rem !important/);
  assert.match(css, /\[data-lf-checkout-form="true"\] \.field-label/);
});

test("mobile checkout medium size tuning", () => {
  const checkout = read("app/checkout/page.tsx");
  const css = read("app/globals.css");

  assert.match(checkout, /min-h-\[78px\]/);
  assert.match(checkout, /min-h-\[40px\]/);
  assert.match(checkout, /size-\[26px\]/);
  assert.match(css, /height: 1\.9rem !important/);
  assert.match(css, /min-height: 2\.125rem/);
});
