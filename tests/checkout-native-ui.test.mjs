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

test("additional nickname checks are optional and do not block checkout", () => {
  const checkout = read("app/checkout/page.tsx");
  assert.match(checkout, /const requiredNicknameGames = new Set/);
  assert.match(checkout, /const optionalNicknameGames = new Set/);
  assert.match(checkout, /nicknameRequired && visibleNickname\.status !== "success"/);
  assert.match(checkout, /blocking=\{nicknameRequired\}/);
  assert.match(checkout, /publicNicknameMessage/);
});

test("checkout payment types are Midtrans Snap only", () => {
  const checkout = read("app/checkout/page.tsx");
  assert.doesNotMatch(checkout, /bisnap|BI-SNAP/i);
  assert.match(checkout, /midtransMode\?: "snap"/);
});
