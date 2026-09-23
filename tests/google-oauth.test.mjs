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

test("Google Identity backend verifies signed ID token and same-origin POST", () => {
  const route = read("app/api/auth/google/route.ts");
  const helper = read("lib/server/google-oauth.ts");
  assert.match(route, /rejectCrossOriginMutation/);
  assert.match(route, /allowRequest/);
  assert.match(route, /credential/);
  assert.match(route, /customerSessionCookie/);
  assert.match(helper, /jwtVerify/);
  assert.match(helper, /email_verified/);
  assert.match(helper, /audience: clientId/);
  assert.match(helper, /accounts\.google\.com/);
  assert.doesNotMatch(helper, /clientSecret|GOOGLE_TOKEN_URL|authorization code/i);
});

test("new Google customer and OAuth link are created in one D1 batch", () => {
  const source = read("lib/server/customer-auth.ts");
  assert.match(source, /await db\.batch\(\[customerInsert, oauthInsert\]\)/);
  assert.doesNotMatch(source, /ON CONFLICT\(provider, provider_subject\) DO UPDATE SET\s+customer_id/);
});

test("Google Login integration stores only Client ID and no Client Secret", () => {
  const integration = read("lib/server/integration-config.ts");
  const admin = read("components/admin-integration-workspace.tsx");
  assert.match(integration, /"google:service": \["clientId"\]/);
  assert.match(integration, /GOOGLE_OAUTH_CLIENT_ID/);
  assert.doesNotMatch(integration, /GOOGLE_OAUTH_CLIENT_SECRET|clientSecret/);
  assert.match(admin, /Client ID/);
  assert.doesNotMatch(admin, /Text label="Client Secret"/);
  assert.doesNotMatch(admin, /CopyUrl label="Authorized Redirect URI"/);
});

test("customer auth UI uses Google Identity Services and posts credential to backend", () => {
  const form = read("components/customer-auth-form.tsx");
  const status = read("app/api/auth/google/status/route.ts");
  assert.match(form, /https:\/\/accounts\.google\.com\/gsi\/client/);
  assert.match(form, /google\.accounts\.id\.initialize/);
  assert.match(form, /google\.accounts\.id\.renderButton/);
  assert.match(form, /fetch\("\/api\/auth\/google"/);
  assert.match(form, /submitGoogleCredential\(response\.credential\)/);
  assert.match(status, /clientId/);
});


test("Google customer cannot be created or continue with an empty contact number", () => {
  const auth = read("lib/server/customer-auth.ts");
  const route = read("app/api/auth/google/route.ts");
  const form = read("components/customer-auth-form.tsx");
  assert.match(auth, /phone\?: string/);
  assert.match(auth, /PHONE_REQUIRED/);
  assert.match(auth, /normalizeWhatsappPhone\(input\.phone\)/);
  assert.match(route, /code: "PHONE_REQUIRED"/);
  assert.match(route, /phone: z\.string\(\)\.trim\(\)/);
  assert.match(form, /Nomor kontak wajib/);
  assert.match(form, /Simpan nomor & lanjutkan/);
  assert.match(form, /payload\.code === "PHONE_REQUIRED"/);
});
