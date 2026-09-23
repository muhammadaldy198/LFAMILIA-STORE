import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";
import test from "node:test";

const cleanup = fs.readFileSync(path.join(process.cwd(), "lib/server/customer-cleanup.ts"), "utf8");
const route = fs.readFileSync(path.join(process.cwd(), "app/api/admin/customer-cleanup/route.ts"), "utf8");
const members = fs.readFileSync(path.join(process.cwd(), "app/api/admin/members/route.ts"), "utf8");
const panelRouter = fs.readFileSync(path.join(process.cwd(), "app/api/panel/[...path]/route.ts"), "utf8");
const workspace = fs.readFileSync(path.join(process.cwd(), "components/admin-customer-workspace.tsx"), "utf8");
const worker = fs.readFileSync(path.join(process.cwd(), "worker/index.ts"), "utf8");

test("automatic cleanup is strict and never touches staff/admin identities", () => {
  assert.match(cleanup, /email NOT LIKE '__lfadmin__:%'/);
  assert.match(cleanup, /balance = 0/);
  assert.match(cleanup, /NOT EXISTS \(SELECT 1 FROM orders/);
  assert.match(cleanup, /NOT EXISTS \(SELECT 1 FROM wallet_topups/);
  assert.match(cleanup, /NOT EXISTS \(SELECT 1 FROM wallet_transactions/);
  assert.match(cleanup, /COALESCE\(last_login_at, created_at\)/);
  assert.match(cleanup, /inactivityDays/);
});

test("manual hard delete is super-admin only and race-safe", () => {
  assert.match(members, /requireAdminSession\(request, "owner"\)/);
  assert.match(members, /permanentlyDeleteEmptyCustomer/);
  assert.match(cleanup, /DELETE FROM customer_users/);
  assert.match(cleanup, /AND balance = 0/);
  assert.match(cleanup, /NOT EXISTS \(SELECT 1 FROM orders o WHERE o\.customer_id = customer_users\.id\)/);
  assert.match(workspace, /Ketik HAPUS/);
  assert.match(panelRouter, /members: \{ GET: members\.GET, PUT: members\.PUT, PATCH: members\.PATCH, DELETE: members\.DELETE \}/);
});

test("cleanup settings are configurable from the super admin panel", () => {
  assert.match(route, /inactivityDays: z\.number\(\)\.int\(\)\.min\(7\)\.max\(365\)/);
  assert.match(route, /saveCustomerCleanupSettings/);
  assert.match(panelRouter, /"customer-cleanup": \{ GET: customerCleanup\.GET, PUT: customerCleanup\.PUT, POST: customerCleanup\.POST \}/);
  assert.match(workspace, /Pembersihan Akun Kosong/);
  assert.match(workspace, /Hapus otomatis/);
  assert.match(workspace, /Jalankan Sekarang/);
});

test("daily scheduler runs dormant-customer cleanup without adding a new cron", () => {
  assert.match(worker, /cleanupDormantCustomerAccounts/);
  assert.match(worker, /event\.cron === "15 2 \* \* \*"/);
  assert.match(worker, /Pembersihan akun pelanggan kosong gagal/);
});
