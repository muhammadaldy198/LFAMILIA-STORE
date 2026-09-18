import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";
import test from "node:test";
import { fileURLToPath } from "node:url";
import { DatabaseSync } from "node:sqlite";

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), "..");
const read = (file) => fs.readFileSync(path.join(root, file), "utf8");

test("0037 adds secure phone-verification and guest-review schema without losing legacy reviews", () => {
  const db = new DatabaseSync(":memory:");
  db.exec("PRAGMA foreign_keys = ON");
  db.exec(`
    CREATE TABLE customer_users (
      id TEXT PRIMARY KEY NOT NULL,
      email TEXT NOT NULL,
      name TEXT NOT NULL,
      phone TEXT NOT NULL,
      password_hash TEXT NOT NULL DEFAULT '',
      password_salt TEXT NOT NULL DEFAULT '',
      balance INTEGER NOT NULL DEFAULT 0,
      leaderboard_opt_in INTEGER NOT NULL DEFAULT 0,
      is_active INTEGER NOT NULL DEFAULT 1,
      created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    );
    CREATE TABLE orders (
      id TEXT PRIMARY KEY NOT NULL
    );
    CREATE TABLE product_reviews (
      id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
      customer_id TEXT NOT NULL,
      product_slug TEXT NOT NULL,
      rating INTEGER NOT NULL,
      title TEXT,
      body TEXT NOT NULL,
      is_verified_purchase INTEGER NOT NULL DEFAULT 0,
      is_visible INTEGER NOT NULL DEFAULT 1,
      created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
      FOREIGN KEY (customer_id) REFERENCES customer_users(id) ON DELETE CASCADE
    );
    CREATE UNIQUE INDEX product_reviews_customer_product_unique
      ON product_reviews(customer_id, product_slug);
    CREATE INDEX product_reviews_product_visible_idx
      ON product_reviews(product_slug, is_visible, created_at);
  `);

  db.prepare("INSERT INTO customer_users (id,email,name,phone) VALUES (?,?,?,?)")
    .run("customer-a", "a@example.com", "Alday Test", "081234567890");
  db.prepare("INSERT INTO customer_users (id,email,name,phone) VALUES (?,?,?,?)")
    .run("customer-b", "b@example.com", "Duplicate Test", "6281234567890");
  db.prepare("INSERT INTO customer_users (id,email,name,phone) VALUES (?,?,?,?)")
    .run("customer-c", "c@example.com", "Unique Test", "081355566677");
  db.prepare(`INSERT INTO product_reviews
    (customer_id, product_slug, rating, title, body, is_verified_purchase, is_visible)
    VALUES (?, ?, ?, ?, ?, 1, 1)`)
    .run("customer-a", "mobile-legends", 5, "Cepat", "Masuk dengan cepat.");

  db.exec(read("drizzle/0037_customer_phone_whatsapp_reviews.sql").replaceAll("--> statement-breakpoint", ""));

  const phoneColumns = new Set(db.prepare("PRAGMA table_info(customer_users)").all().map((row) => row.name));
  assert.ok(phoneColumns.has("phone_verified_at"));

  const otpColumns = new Set(db.prepare("PRAGMA table_info(customer_phone_otp_challenges)").all().map((row) => row.name));
  for (const column of ["otp_hash", "otp_salt", "expires_at", "attempt_count", "consumed_at"]) {
    assert.ok(otpColumns.has(column), `${column} should exist`);
  }

  const duplicateRows = db.prepare("SELECT phone, phone_verified_at FROM customer_users WHERE id IN ('customer-a','customer-b') ORDER BY id").all();
  assert.equal(duplicateRows[0].phone, "+6281234567890");
  assert.equal(duplicateRows[1].phone, "+6281234567890");
  assert.equal(duplicateRows[0].phone_verified_at, null);
  assert.equal(duplicateRows[1].phone_verified_at, null);

  const uniqueRow = db.prepare("SELECT phone, phone_verified_at FROM customer_users WHERE id = 'customer-c'").get();
  assert.equal(uniqueRow.phone, "+6281355566677");
  assert.ok(uniqueRow.phone_verified_at);

  const reviewColumns = new Map(db.prepare("PRAGMA table_info(product_reviews)").all().map((row) => [row.name, row]));
  assert.ok(reviewColumns.has("order_id"));
  assert.ok(reviewColumns.has("reviewer_name"));
  assert.equal(reviewColumns.get("customer_id").notnull, 0);

  const legacy = db.prepare("SELECT customer_id, reviewer_name, product_slug, rating FROM product_reviews WHERE customer_id = 'customer-a'").get();
  assert.equal(legacy.reviewer_name, "Alday Test");
  assert.equal(legacy.product_slug, "mobile-legends");
  assert.equal(legacy.rating, 5);
});

test("WhatsApp OTP backend hashes codes, expires challenges, and calls WhatsApp template API", () => {
  const source = read("lib/server/whatsapp-otp.ts");
  assert.match(source, /PBKDF2/);
  assert.match(source, /OTP_PBKDF2_ITERATIONS\s*=\s*120_000/);
  assert.match(source, /OTP_TTL_MS\s*=\s*5\s*\*\s*60\s*\*\s*1000/);
  assert.match(source, /OTP_MAX_ATTEMPTS\s*=\s*5/);
  assert.match(source, /WHATSAPP_ACCESS_TOKEN/);
  assert.match(source, /messaging_product:\s*"whatsapp"/);
  assert.match(source, /type:\s*"template"/);
  assert.match(source, /otp_hash/);
  assert.doesNotMatch(source, /INSERT INTO customer_phone_otp_challenges[\s\S]{0,300}\botp\b\s*,/i);
});

test("customer session exposes phone verification and protected account routes require it", () => {
  const auth = read("lib/server/customer-auth.ts");
  const account = read("app/api/account/route.ts");
  const send = read("app/api/account/phone/send-otp/route.ts");
  const verify = read("app/api/account/phone/verify-otp/route.ts");
  assert.match(auth, /phoneVerified:\s*boolean/);
  assert.match(auth, /requiresPhoneVerification:\s*true/);
  assert.match(auth, /allowUnverifiedPhone/);
  assert.match(account, /allowUnverifiedPhone:\s*true/);
  assert.match(send, /createWhatsappOtpChallenge/);
  assert.match(verify, /verifyWhatsappOtpChallenge/);
  assert.match(send, /allowRequest/);
  assert.match(verify, /allowRequest/);
});

test("sidebar switches from guest CTA to signed-in balance card", () => {
  const header = read("components/store-header.tsx");
  const authForm = read("components/customer-auth-form.tsx");
  assert.match(header, /fetch\("\/api\/account"/);
  assert.match(header, /formatRupiah\(customer\.balance\)/);
  assert.match(header, /WhatsApp terverifikasi/);
  assert.match(header, /lfamilia:auth-changed/);
  assert.match(authForm, /lfamilia:auth-changed/);
});

test("guest buyers can review a paid order using invoice and checkout WhatsApp", () => {
  const route = read("app/api/reviews/route.ts");
  const reviews = read("lib/server/reviews.ts");
  const ui = read("components/product-reviews.tsx");
  assert.match(route, /saveGuestProductReview/);
  assert.doesNotMatch(route, /requireCustomerSession/);
  assert.match(reviews, /payment_status\s*=\s*'paid'/);
  assert.match(reviews, /buyer_phone/);
  assert.match(reviews, /product_reviews WHERE order_id/);
  assert.match(ui, /Nomor invoice/);
  assert.match(ui, /Nomor WhatsApp saat checkout/);
  assert.match(ui, /Satu invoice hanya bisa memberi satu ulasan/);
});

test("WhatsApp OTP credentials are dashboard-managed and never hardcoded", () => {
  const integration = read("lib/server/integration-config.ts");
  const admin = read("components/admin-integration-workspace.tsx");
  assert.match(integration, /"whatsapp:service"/);
  assert.match(integration, /WHATSAPP_ACCESS_TOKEN/);
  assert.match(integration, /applyWhatsappConfig/);
  assert.match(admin, /WhatsApp OTP/);
  assert.match(admin, /Access Token/);
  assert.match(admin, /whatsappRequiredFields/);
});
