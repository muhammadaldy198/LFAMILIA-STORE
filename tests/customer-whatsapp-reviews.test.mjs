import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";
import test from "node:test";
import { fileURLToPath } from "node:url";
import { DatabaseSync } from "node:sqlite";

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), "..");
const read = (file) => fs.readFileSync(path.join(root, file), "utf8");
const exists = (file) => fs.existsSync(path.join(root, file));

test("0037 legacy phone/review schema remains compatible with production data", () => {
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

  const reviewColumns = new Map(db.prepare("PRAGMA table_info(product_reviews)").all().map((row) => [row.name, row]));
  assert.ok(reviewColumns.has("order_id"));
  assert.ok(reviewColumns.has("reviewer_name"));
  assert.equal(reviewColumns.get("customer_id").notnull, 0);

  const legacy = db.prepare("SELECT customer_id, reviewer_name, product_slug, rating FROM product_reviews WHERE customer_id = 'customer-a'").get();
  assert.equal(legacy.reviewer_name, "Alday Test");
  assert.equal(legacy.product_slug, "mobile-legends");
  assert.equal(legacy.rating, 5);
});

test("WhatsApp OTP runtime and endpoints are removed", () => {
  assert.equal(exists("lib/server/whatsapp-otp.ts"), false);
  assert.equal(exists("components/customer-phone-verification.tsx"), false);
  assert.equal(exists("app/api/account/phone/send-otp/route.ts"), false);
  assert.equal(exists("app/api/account/phone/verify-otp/route.ts"), false);

  const auth = read("lib/server/customer-auth.ts");
  const account = read("components/customer-account.tsx");
  assert.doesNotMatch(auth, /requiresPhoneVerification|allowUnverifiedPhone/);
  assert.doesNotMatch(account, /CustomerPhoneVerification|phoneVerified\)\s*\{/);
  assert.match(account, /LFAMILIA tidak mengirim OTP WhatsApp/);
});

test("customer profile can update its contact number without OTP", () => {
  const route = read("app/api/account/route.ts");
  const account = read("components/customer-account.tsx");
  assert.match(route, /SET name = \?, phone = \?, phone_verified_at = NULL/);
  assert.match(account, /Field label="Nomor kontak"/);
  assert.match(account, /onChange=\{\(event\) => setPhone\(event\.target\.value\)\}/);
});

test("sidebar switches from guest CTA to signed-in balance card without WhatsApp verification state", () => {
  const header = read("components/store-header.tsx");
  const authForm = read("components/customer-auth-form.tsx");
  assert.match(header, /fetch\("\/api\/account\/summary"/);
  assert.match(header, /formatRupiah\(customer\.balance\)/);
  assert.match(header, /Akun & Saldo/);
  assert.doesNotMatch(header, /WhatsApp terverifikasi|WhatsApp belum diverifikasi|Verifikasi WhatsApp/);
  assert.match(header, /lfamilia:auth-changed/);
  assert.match(authForm, /lfamilia:auth-changed/);
});

test("logged-in reviews use account identity while guest buyers use invoice and checkout contact", () => {
  const route = read("app/api/reviews/route.ts");
  const reviews = read("lib/server/reviews.ts");
  const ui = read("components/product-reviews.tsx");
  assert.match(route, /if \(customer\) \{/);
  assert.match(route, /saveGuestProductReview/);
  assert.doesNotMatch(route, /customer\?\.phoneVerified/);
  assert.match(reviews, /payment_status\s*=\s*'paid'/);
  assert.match(reviews, /buyer_phone/);
  assert.match(reviews, /product_reviews WHERE order_id/);
  assert.match(ui, /Nomor invoice/);
  assert.match(ui, /Nomor kontak saat checkout/);
  assert.match(ui, /Satu invoice hanya bisa memberi satu ulasan/);
});

test("automatic customer messaging is website and email only", () => {
  const integration = read("lib/server/integration-config.ts");
  const admin = read("components/admin-integration-workspace.tsx");
  const vouchers = read("lib/server/vouchers.ts");
  const notifications = read("lib/server/transaction-notifications.ts");

  assert.doesNotMatch(integration, /"whatsapp:service"|WHATSAPP_ACCESS_TOKEN|applyWhatsappConfig/);
  assert.doesNotMatch(admin, /WhatsApp OTP|whatsappRequiredFields|whatsappAccessToken/);
  assert.match(admin, /Resend Email/);
  assert.match(vouchers, /if \(normalized === "website"\) return \[\]/);
  assert.match(vouchers, /if \(normalized === "email"\) return \["email"\]/);
  assert.doesNotMatch(vouchers, /normalized === "whatsapp"|email\+whatsapp|whatsapp\+email/);
  assert.match(notifications, /RESEND_API_KEY/);
  assert.doesNotMatch(notifications, /WHATSAPP_|messaging_product/);
});
