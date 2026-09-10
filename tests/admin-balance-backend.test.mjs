import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";
import test from "node:test";

const root = process.cwd();
const route = fs.readFileSync(path.join(root, "app/api/admin/balances/route.ts"), "utf8");
const panel = fs.readFileSync(path.join(root, "app/api/panel/[...path]/route.ts"), "utf8");
const manager = fs.readFileSync(path.join(root, "components/admin-customer-workspace.tsx"), "utf8");

test("owner can adjust customer or admin ledger balance with a mandatory reason", () => {
  assert.match(route, /accountType: z\.enum\(\["customer", "admin"\]\)/);
  assert.match(route, /operation: z\.enum\(\["credit", "debit"\]\)/);
  assert.match(route, /reason: z\.string\(\)\.trim\(\)\.min\(3\)/);
  assert.match(route, /requireAdminSession\(request, "owner"\)/);
  assert.match(route, /INSERT INTO wallet_transactions/);
  assert.match(route, /balance >= \?/);
  assert.match(route, /Saldo tidak mencukupi untuk dikurangi/);
});

test("admin credentials use their private ledger row and UI calls the endpoint", () => {
  assert.match(route, /'__lfadmin__:' \|\| lower\(a\.email\)/);
  assert.match(panel, /balances: \{ GET: balances\.GET, PUT: balances\.PUT \}/);
  assert.match(manager, /fetch\("\/api\/panel\/balances"/);
  assert.match(manager, /accountType === "Pelanggan" \? "customer" : "admin"/);
  assert.match(manager, /operation === "Tambah" \? "credit" : "debit"/);
  assert.doesNotMatch(manager, /dicatat pada UI/);
});
