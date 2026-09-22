import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";
import test from "node:test";

const root = process.cwd();
const read = (file) => fs.readFileSync(path.join(root, file), "utf8");

test("password reset is tokenized, expiring, single-use, and enumeration-safe", () => {
  const service = read("lib/server/password-reset.ts");
  const forgot = read("app/api/auth/forgot-password/route.ts");
  const reset = read("app/api/auth/reset-password/route.ts");
  const migration = read("drizzle/0041_customer_password_reset.sql");
  assert.match(service, /sha256\(token\)/);
  assert.match(service, /\+15 minutes/);
  assert.match(service, /consumed_at IS NULL/);
  assert.match(service, /DELETE FROM customer_sessions/);
  assert.match(service, /RESEND_API_KEY/);
  assert.match(forgot, /Jika email terdaftar, link reset password telah dikirim/);
  assert.match(forgot, /verifyTurnstile/);
  assert.match(forgot, /customer-forgot-password/);
  assert.match(reset, /customer-reset-password/);
  assert.match(migration, /token_hash TEXT NOT NULL/);
  assert.match(migration, /expires_at TEXT NOT NULL/);
});

test("login exposes forgot-password UI", () => {
  const ui = read("components/customer-auth-form.tsx");
  assert.match(ui, /Lupa password\?/);
  assert.match(ui, /\/api\/auth\/forgot-password/);
  assert.match(ui, /Kirim link reset/);
});

test("technical SEO exposes robots sitemap social metadata and structured data", () => {
  const layout = read("app/layout.tsx");
  const robots = read("app/robots.ts");
  const sitemap = read("app/sitemap.ts");
  const home = read("app/page.tsx");
  assert.match(layout, /metadataBase/);
  assert.match(layout, /openGraph/);
  assert.match(layout, /twitter/);
  assert.match(layout, /application\/ld\+json/);
  assert.match(layout, /schema\.org/);
  assert.match(robots, /\/admin/);
  assert.match(robots, /\/api\//);
  assert.match(robots, /sitemap\.xml/);
  assert.match(sitemap, /news_articles/);
  assert.doesNotMatch(sitemap, /\/product\//);
  assert.match(home, /canonical: "\/"/);
});
