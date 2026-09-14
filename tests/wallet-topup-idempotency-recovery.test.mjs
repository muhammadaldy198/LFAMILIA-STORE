import assert from "node:assert/strict";
import { DatabaseSync } from "node:sqlite";
import fs from "node:fs";
import path from "node:path";
import test from "node:test";

const root = process.cwd();

test("wallet topup concurrent idempotency loser can recover the committed winner", () => {
  const db = new DatabaseSync(":memory:");
  db.exec(`
    CREATE TABLE wallet_topups (
      id TEXT PRIMARY KEY,
      customer_id TEXT NOT NULL,
      amount INTEGER NOT NULL,
      payment_method TEXT NOT NULL,
      source TEXT NOT NULL,
      reference_id TEXT NOT NULL,
      external_checkout_key TEXT,
      status TEXT NOT NULL DEFAULT 'pending'
    );
    CREATE UNIQUE INDEX wallet_topups_external_checkout_key_unique
      ON wallet_topups(customer_id, external_checkout_key)
      WHERE external_checkout_key IS NOT NULL;
  `);

  const insert = db.prepare(`
    INSERT INTO wallet_topups
      (id, customer_id, amount, payment_method, source, reference_id, external_checkout_key)
    VALUES (?, ?, ?, ?, 'doku', ?, ?)
  `);
  insert.run("winner", "customer-1", 10000, "qris:mpm", "WLT-WINNER", "same-key");
  assert.throws(
    () => insert.run("loser", "customer-1", 10000, "qris:mpm", "WLT-LOSER", "same-key"),
    /UNIQUE constraint failed/,
  );

  const winner = db.prepare(`
    SELECT id, amount, payment_method, reference_id
    FROM wallet_topups
    WHERE customer_id = ? AND external_checkout_key = ? AND source = 'doku'
  `).get("customer-1", "same-key");
  assert.deepEqual(
    { ...winner },
    { id: "winner", amount: 10000, payment_method: "qris:mpm", reference_id: "WLT-WINNER" },
  );
  db.close();
});

test("same topup idempotency key cannot be rebound to a different request", () => {
  const db = new DatabaseSync(":memory:");
  db.exec(`
    CREATE TABLE wallet_topups (
      id TEXT PRIMARY KEY,
      customer_id TEXT NOT NULL,
      amount INTEGER NOT NULL,
      payment_method TEXT NOT NULL,
      source TEXT NOT NULL,
      reference_id TEXT NOT NULL,
      external_checkout_key TEXT
    );
    CREATE UNIQUE INDEX wallet_topups_external_checkout_key_unique
      ON wallet_topups(customer_id, external_checkout_key)
      WHERE external_checkout_key IS NOT NULL;
    INSERT INTO wallet_topups VALUES ('winner','customer-1',10000,'qris:mpm','doku','WLT-WINNER','same-key');
  `);
  const winner = db.prepare(`SELECT amount, payment_method FROM wallet_topups
    WHERE customer_id=? AND external_checkout_key=?`).get("customer-1", "same-key");
  const requested = { amount: 20000, payment_method: "va:bca" };
  assert.equal(
    winner.amount === requested.amount && winner.payment_method === requested.payment_method,
    false,
  );
  db.close();
});

test("production topup route re-reads the winner and validates request binding after a UNIQUE race", () => {
  const route = fs.readFileSync(path.join(root, "app/api/account/topups/route.ts"), "utf8");
  assert.match(route, /UNIQUE constraint failed/);
  assert.match(route, /findExternalTopupByKey\(customer\.id, idempotencyKey\)/);
  assert.match(route, /winner\.amount !== requestedAmount/);
  assert.match(route, /winner\.payment_method !== requestedPaymentMethodKey/);
});
