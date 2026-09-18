import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";
import test from "node:test";
import { fileURLToPath } from "node:url";
import { DatabaseSync } from "node:sqlite";

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), "..");
const read = (file) => fs.readFileSync(path.join(root, file), "utf8");

test("Google OAuth migration creates unique provider links", () => {
  const db = new DatabaseSync(":memory:");
  db.exec("PRAGMA foreign_keys = ON");
  db.exec(`CREATE TABLE customer_users (
    id TEXT PRIMARY KEY NOT NULL,
    email TEXT NOT NULL UNIQUE
  )`);
  db.exec(read("drizzle/0036_customer_google_oauth.sql").replaceAll("--> statement-breakpoint", ""));
  db.prepare("INSERT INTO customer_users (id, email) VALUES (?, ?)").run("customer-a", "a@example.com");
  db.prepare(`INSERT INTO customer_oauth_accounts
    (id, customer_id, provider, provider_subject, provider_email)
    VALUES (?, ?, ?, ?, ?)`)
    .run("oauth-a", "customer-a", "google", "subject-a", "a@example.com");
  assert.throws(() => db.prepare(`INSERT INTO customer_oauth_accounts
    (id, customer_id, provider, provider_subject, provider_email)
    VALUES (?, ?, ?, ?, ?)`)
    .run("oauth-b", "customer-a", "google", "subject-b", "a@example.com"), /UNIQUE/i);
  db.prepare("DELETE FROM customer_users WHERE id = ?").run("customer-a");
  assert.equal(db.prepare("SELECT COUNT(*) AS count FROM customer_oauth_accounts").get().count, 0);
});

test("Google OAuth flow uses state, nonce, HttpOnly cookies, and verified ID tokens", () => {
  const start = read("app/api/auth/google/route.ts");
  const callback = read("app/api/auth/google/callback/route.ts");
  const helper = read("lib/server/google-oauth.ts");
  assert.match(start, /state/);
  assert.match(start, /nonce/);
  assert.match(start, /HttpOnly/);
  assert.match(start, /SameSite=Lax/);
  assert.match(callback, /state !== expectedState/);
  assert.match(helper, /jwtVerify/);
  assert.match(helper, /email_verified/);
  assert.match(helper, /audience: clientId/);
});

test("Google OAuth credentials stay dashboard-managed and encrypted", () => {
  const integration = read("lib/server/integration-config.ts");
  const admin = read("components/admin-integration-workspace.tsx");
  assert.match(integration, /"google:service": \["clientId", "clientSecret"\]/);
  assert.match(integration, /GOOGLE_OAUTH_CLIENT_SECRET/);
  assert.match(integration, /encryptConfig/);
  assert.match(admin, /Client Secret/);
  assert.doesNotMatch(integration, /clientSecret:\s*"[A-Za-z0-9_-]{20,}"/);
});

test("customer auth UI exposes Google login only after backend readiness check", () => {
  const form = read("components/customer-auth-form.tsx");
  assert.match(form, /\/api\/auth\/google\/status/);
  assert.match(form, /Masuk dengan Google/);
  assert.match(form, /googleReady &&/);
});
