import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";
import test from "node:test";

const root = process.cwd();
const read = (file) => fs.readFileSync(path.join(root, file), "utf8");

test("forgot-password flow is enumeration-safe, rate limited, Turnstile protected, and email based", () => {
  const request = read("app/api/auth/forgot-password/route.ts");
  const service = read("lib/server/password-reset.ts");
  assert.match(request, /customer-forgot-password/);
  assert.match(request, /verifyTurnstile/);
  assert.match(request, /Jika email terdaftar/);
  assert.match(service, /RESEND_API_KEY/);
  assert.match(service, /RESET_TTL_MINUTES = 15/);
  assert.match(service, /token_hash/);
  assert.match(service, /sha256\(token\)/);
  assert.doesNotMatch(service, /INSERT INTO customer_password_reset_tokens[^]*?\?[^]*?token,/);
});

test("password reset is one-time and revokes old customer sessions", () => {
  const service = read("lib/server/password-reset.ts");
  const route = read("app/api/auth/reset-password/route.ts");
  assert.match(service, /used_at IS NULL AND expires_at > CURRENT_TIMESTAMP/);
  assert.match(service, /RETURNING customer_id/);
  assert.match(service, /getPublicBaseUrl/);
  assert.match(service, /UPDATE customer_password_reset_tokens SET used_at = CURRENT_TIMESTAMP/);
  assert.match(service, /DELETE FROM customer_sessions WHERE customer_id = \?/);
  assert.match(route, /customer-reset-password/);
  assert.match(route, /min\(8/);
});

test("login UI exposes forgot password and SEO routes exclude private surfaces", () => {
  const auth = read("components/customer-auth-form.tsx");
  const robots = read("app/robots.ts");
  const sitemap = read("app/sitemap.ts");
  const layout = read("app/layout.tsx");
  assert.match(auth, /\/forgot-password/);
  assert.match(robots, /\/admin/);
  assert.match(robots, /\/api\//);
  assert.match(robots, /\/checkout/);
  assert.match(sitemap, /news_articles WHERE is_published = 1/);
  assert.match(layout, /metadataBase/);
  assert.match(layout, /openGraph/);
  assert.match(layout, /application\/ld\+json/);
});
