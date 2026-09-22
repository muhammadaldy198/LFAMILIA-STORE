import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";
import test from "node:test";
import { fileURLToPath } from "node:url";
import { DatabaseSync } from "node:sqlite";

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), "..");
const read = (file) => fs.readFileSync(path.join(root, file), "utf8");

test("0041 creates one-time customer password reset token storage", () => {
  const db = new DatabaseSync(":memory:");
  db.exec("PRAGMA foreign_keys = ON");
  db.exec(`
    CREATE TABLE customer_users (
      id TEXT PRIMARY KEY NOT NULL,
      email TEXT NOT NULL,
      is_active INTEGER NOT NULL DEFAULT 1
    );
  `);
  db.exec(read("drizzle/0041_customer_password_reset.sql").replaceAll("--> statement-breakpoint", ""));
  const columns = new Set(db.prepare("PRAGMA table_info(customer_password_reset_tokens)").all().map((row) => row.name));
  for (const column of ["id", "customer_id", "token_hash", "expires_at", "consumed_at", "created_at"]) {
    assert.ok(columns.has(column), `${column} should exist`);
  }
  const indexes = db.prepare("PRAGMA index_list(customer_password_reset_tokens)").all().map((row) => row.name);
  assert.ok(indexes.includes("customer_password_reset_token_unique"));
  assert.ok(indexes.includes("customer_password_reset_expiry_idx"));
});

test("password reset service stores only token hashes, expires in 15 minutes, and revokes sessions", () => {
  const source = read("lib/server/password-reset.ts");
  assert.match(source, /RESET_TTL_MS\s*=\s*15\s*\*\s*60\s*\*\s*1000/);
  assert.match(source, /token_hash/);
  assert.match(source, /sha256\(token\)/);
  assert.doesNotMatch(source, /INSERT INTO customer_password_reset_tokens[\s\S]{0,300}\btoken\s*,/i);
  assert.match(source, /consumed_at IS NULL/);
  assert.match(source, /expires_at > CURRENT_TIMESTAMP/);
  assert.match(source, /DELETE FROM customer_sessions/);
  assert.match(source, /createCustomerPasswordCredentials/);
  assert.match(source, /idempotency-key/);
  assert.match(source, /Link ini berlaku selama 15 menit/);
});

test("forgot password route is enumeration-safe and abuse protected", () => {
  const forgot = read("app/api/auth/forgot-password/route.ts");
  const reset = read("app/api/auth/reset-password/route.ts");
  assert.match(forgot, /customer-forgot-password/);
  assert.match(forgot, /verifyTurnstile/);
  assert.match(forgot, /Jika email tersebut terdaftar/);
  assert.match(forgot, /passwordResetEmailConfigured/);
  assert.doesNotMatch(forgot, /Email tidak ditemukan|akun tidak ditemukan/i);
  assert.match(reset, /customer-reset-password/);
  assert.match(reset, /verifyTurnstile/);
  assert.match(reset, /consumePasswordResetToken/);
});

test("customer login exposes forgot-password flow", () => {
  const auth = read("components/customer-auth-form.tsx");
  const recovery = read("components/password-recovery.tsx");
  assert.match(auth, /href="\/forgot-password"/);
  assert.match(auth, /Lupa password\?/);
  assert.match(recovery, /\/api\/auth\/forgot-password/);
  assert.match(recovery, /\/api\/auth\/reset-password/);
  assert.match(recovery, /new URLSearchParams\(window\.location\.search\)/);
});

test("technical SEO publishes canonical metadata, robots, sitemap, social cards, and JSON-LD", () => {
  const seo = read("lib/seo.ts");
  const layout = read("app/layout.tsx");
  const robots = read("app/robots.ts");
  const sitemap = read("app/sitemap.ts");
  const home = read("app/page.tsx");
  assert.match(seo, /alternates:\s*\{ canonical:/);
  assert.match(seo, /openGraph/);
  assert.match(seo, /twitter/);
  assert.match(layout, /metadataBase:/);
  assert.match(layout, /application\/ld\+json/);
  assert.match(layout, /storefrontJsonLd/);
  assert.match(robots, /\/admin\//);
  assert.match(robots, /\/api\//);
  assert.match(robots, /sitemap\.xml/);
  assert.match(sitemap, /\/catalog/);
  assert.match(sitemap, /\/news/);
  assert.match(home, /path:\s*"\/"\s*,/);
});

test("mobile customer and admin navigation respect dynamic viewport and safe areas", () => {
  const header = read("components/store-header.tsx");
  const admin = read("components/admin-dashboard.tsx");
  const css = read("app/globals.css");
  assert.match(header, /mobile-store-menu/);
  assert.match(header, /h-dvh/);
  assert.match(admin, /w-\[min\(280px,88vw\)\]/);
  assert.match(css, /mobile-store-menu/);
  assert.match(css, /safe-area-inset-bottom/);
});
