import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";
import test from "node:test";

const root = process.cwd();
const read = (file) => fs.readFileSync(path.join(root, file), "utf8");

test("checkout rejects invalid product slugs and renders live storefront FAQs", () => {
  const checkout = read("app/checkout/page.tsx");
  assert.match(checkout, /requestedProduct\s*\?\s*products\.find/);
  assert.doesNotMatch(checkout, /products\.find\(\(item\) => item\.slug === requestedProduct\) \?\? products\[0\]/);
  assert.match(checkout, /Produk tidak ditemukan/);
  assert.match(checkout, /useStorefront\(\)/);
  assert.match(checkout, /faqs\.filter/);
  assert.doesNotMatch(checkout, /\["Bagaimana cara top up\?"/);
});

test("product admin uses real pagination and explicit nominal action", () => {
  const products = read("components/admin-product-manager.tsx");
  assert.match(products, /const \[page, setPage\] = useState\(1\)/);
  assert.match(products, /const \[pageSize, setPageSize\] = useState\(25\)/);
  assert.match(products, /visibleProducts\.slice\(\(activePage - 1\) \* pageSize, activePage \* pageSize\)/);
  assert.match(products, /25 \/ halaman/);
  assert.match(products, />Nominal<\/button>/);
  assert.doesNotMatch(products, /MoreVertical/);
});

test("admin global search queries real order product and customer records", () => {
  const dashboard = read("components/admin-dashboard.tsx");
  assert.match(dashboard, /fetch\("\/api\/panel\/orders"/);
  assert.match(dashboard, /fetch\("\/api\/panel\/products"/);
  assert.match(dashboard, /fetch\("\/api\/panel\/members"/);
  assert.match(dashboard, /globalResults\.map/);
  assert.match(dashboard, /Tidak ada hasil/);
});

test("banner admin mirrors image-only customer banner and supports mobile uploads", () => {
  const manager = read("components/admin-experience-manager.tsx");
  assert.match(manager, /Gambar Desktop/);
  assert.match(manager, /Gambar Mobile/);
  assert.match(manager, /Link Tujuan \(opsional — klik gambar\)/);
  assert.match(manager, /target === "mobile" \? \{ mobileImageUrl: url \}/);
  assert.match(manager, /mode === "mobile" \? \(banner\.raw\?\.mobileImageUrl \|\| banner\.raw\?\.imageUrl\)/);
  assert.doesNotMatch(manager, /EditorTextArea label="Subjudul"/);
  assert.doesNotMatch(manager, /EditorField label="Teks tombol"/);
  assert.match(manager, /onCancel=\{\(\) => void loadContent\(\)\}/);
});

test("customer account distinguishes transient failures from unauthenticated state", () => {
  const account = read("components/customer-account.tsx");
  assert.match(account, /accountResponse\.status === 401/);
  assert.match(account, /Akun sementara tidak dapat dimuat/);
  assert.match(account, /Coba lagi/);
  assert.match(account, /formatAccountDateTime/);
  assert.match(account, /window\.setTimeout\(\(\) => setCopied/);
});

test("WhatsApp OTP resend obeys a visible cooldown", () => {
  const otp = read("components/customer-phone-verification.tsx");
  assert.match(otp, /const \[resendIn, setResendIn\] = useState\(0\)/);
  assert.match(otp, /payload\.resendAfterSeconds/);
  assert.match(otp, /disabled=\{busy \|\| resendIn > 0\}/);
  assert.match(otp, /Kirim ulang dalam/);
});

test("membership refresh is event driven instead of polling every ten seconds", () => {
  const membership = read("components/customer-membership-summary.tsx");
  assert.doesNotMatch(membership, /setInterval\(\(\) => void load\(\), 10_000\)/);
  assert.match(membership, /lfamilia:auth-changed/);
  assert.match(membership, /window\.addEventListener\("focus"/);
});

test("home content failures expose retry paths and product cards tolerate empty packages", () => {
  const banner = read("components/home-banner-carousel.tsx");
  const news = read("components/home-news-preview.tsx");
  const reviews = read("components/home-reviews-preview.tsx");
  const promotions = read("components/promotion-showcase.tsx");
  const product = read("components/product-card.tsx");
  assert.match(banner, /Coba lagi/);
  assert.match(news, /Muat ulang/);
  assert.match(reviews, /Muat ulang/);
  assert.match(promotions, /Muat ulang/);
  assert.match(product, /product\.packages\.length \? Math\.min/);
  assert.match(product, /Belum tersedia/);
});

test("admin workspaces include explicit responsive breakpoints", () => {
  const orders = read("components/admin-order-manager.tsx");
  const payments = read("components/admin-payment-workspace.tsx");
  const customers = read("components/admin-customer-workspace.tsx");
  const integrations = read("components/admin-integration-workspace.tsx");
  const operations = read("components/admin-operations-workspaces.tsx");
  assert.match(orders, /xl:grid-cols-6/);
  assert.match(orders, /xl:grid-cols-\[minmax\(0,1fr\)_270px\]/);
  assert.match(payments, /xl:grid-cols-\[minmax\(0,1fr\)_410px\]/);
  assert.match(payments, /min-w-\[760px\]/);
  assert.match(customers, /xl:grid-cols-5/);
  assert.match(integrations, /md:grid-cols-2/);
  assert.match(operations, /xl:grid-cols-4/);
});
