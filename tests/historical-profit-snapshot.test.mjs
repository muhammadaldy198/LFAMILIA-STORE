import assert from "node:assert/strict";
import { DatabaseSync } from "node:sqlite";
import fs from "node:fs";
import path from "node:path";
import test from "node:test";

const root = process.cwd();
const read = (file) => fs.readFileSync(path.join(root, file), "utf8");

function readProfit(db) {
  return db.prepare(`
    SELECT
      COUNT(*) AS total_orders,
      COALESCE(SUM(CASE WHEN o.payment_status = 'paid' THEN o.total ELSE 0 END), 0) AS revenue,
      COALESCE(SUM(CASE WHEN o.payment_status = 'paid'
        THEN MAX(o.total - COALESCE(o.supplier_cost_snapshot, 0), 0) ELSE 0 END), 0) AS profit
    FROM orders o
  `).get();
}

test("historical profit remains unchanged when the current supplier price changes", () => {
  const db = new DatabaseSync(":memory:");
  db.exec(`
    CREATE TABLE orders (
      id TEXT PRIMARY KEY,
      package_sku TEXT NOT NULL,
      total INTEGER NOT NULL,
      supplier_cost_snapshot INTEGER,
      payment_status TEXT NOT NULL
    );
    CREATE TABLE product_packages (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      sku TEXT NOT NULL,
      supplier_price INTEGER
    );
  `);

  db.prepare("INSERT INTO product_packages (sku, supplier_price) VALUES ('SAME-SKU', 7000), ('SAME-SKU', 8500)").run();
  db.prepare("INSERT INTO orders (id, package_sku, total, supplier_cost_snapshot, payment_status) VALUES ('order-1', 'SAME-SKU', 10000, 7000, 'paid')").run();

  assert.deepEqual({ ...readProfit(db) }, { total_orders: 1, revenue: 10000, profit: 3000 });

  db.prepare("UPDATE product_packages SET supplier_price = 1 WHERE sku = 'SAME-SKU'").run();
  assert.deepEqual({ ...readProfit(db) }, { total_orders: 1, revenue: 10000, profit: 3000 });
  db.close();
});

test("admin summary is wired to immutable order supplier snapshots", () => {
  const source = read("app/api/admin/summary/route.ts");
  assert.match(source, /o\.supplier_cost_snapshot/);
  assert.doesNotMatch(source, /o\.total - COALESCE\(pp\.supplier_price/);
  assert.doesNotMatch(source, /LEFT JOIN product_packages pp ON pp\.sku = o\.package_sku/);
});
