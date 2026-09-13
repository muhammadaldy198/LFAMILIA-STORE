import assert from "node:assert/strict";
import { DatabaseSync } from "node:sqlite";
import fs from "node:fs";
import path from "node:path";
import test from "node:test";

const root = process.cwd();
const read = (file) => fs.readFileSync(path.join(root, file), "utf8");

function createOrderDb() {
  const db = new DatabaseSync(":memory:");
  db.exec(`
    CREATE TABLE orders (
      id TEXT PRIMARY KEY,
      payment_status TEXT NOT NULL,
      fulfillment_status TEXT NOT NULL,
      doku_expired_at TEXT
    );
  `);
  return db;
}

function applyPaidOrder(db, id) {
  const paid = db.prepare(`
    UPDATE orders
    SET payment_status = 'paid', fulfillment_status = 'processing'
    WHERE id = ? AND payment_status = 'pending'
      AND (doku_expired_at IS NULL OR datetime(doku_expired_at) > datetime('now'))
  `).run(id);
  if (Number(paid.changes) > 0) return true;
  db.prepare(`
    UPDATE orders
    SET payment_status = 'expired'
    WHERE id = ? AND payment_status = 'pending'
      AND doku_expired_at IS NOT NULL
      AND datetime(doku_expired_at) <= datetime('now')
  `).run(id);
  return false;
}

test("DOKU order paid transition accepts an unexpired invoice and rejects a late paid event", () => {
  const db = createOrderDb();
  db.exec(`
    INSERT INTO orders VALUES ('fresh','pending','waiting_payment',datetime('now','+10 minutes'));
    INSERT INTO orders VALUES ('late','pending','waiting_payment',datetime('now','-1 minute'));
    INSERT INTO orders VALUES ('failed','failed','waiting_payment',datetime('now','+10 minutes'));
  `);

  assert.equal(applyPaidOrder(db, "fresh"), true);
  assert.equal(db.prepare("SELECT payment_status FROM orders WHERE id='fresh'").get().payment_status, "paid");

  assert.equal(applyPaidOrder(db, "late"), false);
  assert.equal(db.prepare("SELECT payment_status FROM orders WHERE id='late'").get().payment_status, "expired");

  assert.equal(applyPaidOrder(db, "failed"), false);
  assert.equal(db.prepare("SELECT payment_status FROM orders WHERE id='failed'").get().payment_status, "failed");
  db.close();
});

function createWalletDb() {
  const db = new DatabaseSync(":memory:");
  db.exec(`
    CREATE TABLE customer_users (
      id TEXT PRIMARY KEY,
      balance INTEGER NOT NULL DEFAULT 0
    );
    CREATE TABLE wallet_topups (
      id TEXT PRIMARY KEY,
      customer_id TEXT NOT NULL,
      amount INTEGER NOT NULL,
      status TEXT NOT NULL,
      doku_expired_at TEXT,
      admin_notes TEXT
    );
    CREATE TABLE wallet_transactions (
      id TEXT PRIMARY KEY,
      customer_id TEXT NOT NULL,
      direction TEXT NOT NULL,
      amount INTEGER NOT NULL,
      balance_before INTEGER NOT NULL,
      balance_after INTEGER NOT NULL,
      reference TEXT NOT NULL UNIQUE,
      description TEXT NOT NULL
    );
    INSERT INTO customer_users VALUES ('c1',0);
  `);
  return db;
}

function applyPaidTopup(db, topupId) {
  const topup = db.prepare("SELECT customer_id, amount FROM wallet_topups WHERE id=?").get(topupId);
  const reference = `topup:${topupId}`;
  db.exec("BEGIN IMMEDIATE");
  try {
    const inserted = db.prepare(`
      INSERT INTO wallet_transactions
        (id, customer_id, direction, amount, balance_before, balance_after, reference, description)
      SELECT ?, t.customer_id, 'credit', t.amount, ledger.balance, ledger.balance + t.amount, ?, 'DOKU topup'
      FROM wallet_topups t CROSS JOIN (
        SELECT COALESCE(SUM(CASE WHEN direction='credit' THEN amount ELSE -amount END),0) AS balance
        FROM wallet_transactions WHERE customer_id=?
      ) ledger
      WHERE t.id=? AND t.status='pending'
        AND (t.doku_expired_at IS NULL OR datetime(t.doku_expired_at) > datetime('now'))
    `).run(crypto.randomUUID(), reference, topup.customer_id, topupId);
    const approved = db.prepare(`
      UPDATE wallet_topups SET status='approved'
      WHERE id=? AND status='pending'
        AND EXISTS (SELECT 1 FROM wallet_transactions WHERE reference=?)
    `).run(topupId, reference);
    db.prepare(`
      UPDATE customer_users
      SET balance=(
        SELECT COALESCE(SUM(CASE WHEN direction='credit' THEN amount ELSE -amount END),0)
        FROM wallet_transactions WHERE customer_id=?
      )
      WHERE id=? AND EXISTS (SELECT 1 FROM wallet_transactions WHERE reference=?)
    `).run(topup.customer_id, topup.customer_id, reference);
    db.exec("COMMIT");
    if (Number(inserted.changes) > 0 && Number(approved.changes) > 0) return true;
  } catch (error) {
    db.exec("ROLLBACK");
    throw error;
  }

  db.prepare(`
    UPDATE wallet_topups SET status='rejected', admin_notes='Pembayaran DOKU kedaluwarsa.'
    WHERE id=? AND status='pending'
      AND doku_expired_at IS NOT NULL
      AND datetime(doku_expired_at) <= datetime('now')
  `).run(topupId);
  return false;
}

test("DOKU wallet topup never credits after stored expiry and remains replay-safe", () => {
  const db = createWalletDb();
  db.exec(`
    INSERT INTO wallet_topups VALUES ('fresh','c1',10000,'pending',datetime('now','+10 minutes'),NULL);
    INSERT INTO wallet_topups VALUES ('late','c1',20000,'pending',datetime('now','-1 minute'),NULL);
  `);

  assert.equal(applyPaidTopup(db, "fresh"), true);
  assert.equal(db.prepare("SELECT status FROM wallet_topups WHERE id='fresh'").get().status, "approved");
  assert.equal(db.prepare("SELECT balance FROM customer_users WHERE id='c1'").get().balance, 10000);
  assert.equal(db.prepare("SELECT COUNT(*) AS count FROM wallet_transactions WHERE reference='topup:fresh'").get().count, 1);

  assert.equal(applyPaidTopup(db, "fresh"), false);
  assert.equal(db.prepare("SELECT COUNT(*) AS count FROM wallet_transactions WHERE reference='topup:fresh'").get().count, 1);

  assert.equal(applyPaidTopup(db, "late"), false);
  assert.equal(db.prepare("SELECT status FROM wallet_topups WHERE id='late'").get().status, "rejected");
  assert.equal(db.prepare("SELECT COUNT(*) AS count FROM wallet_transactions WHERE reference='topup:late'").get().count, 0);
  assert.equal(db.prepare("SELECT balance FROM customer_users WHERE id='c1'").get().balance, 10000);
  db.close();
});

test("production DOKU order and wallet transitions carry the same deadline guards", () => {
  const transition = read("lib/server/doku-payment-transition.ts");
  const wallet = read("lib/server/wallet.ts");

  assert.match(transition, /doku_expired_at IS NULL OR datetime\(doku_expired_at\) > datetime\('now'\)/);
  assert.match(transition, /datetime\(doku_expired_at\) <= datetime\('now'\)/);
  assert.match(wallet, /t\.doku_expired_at IS NULL OR datetime\(t\.doku_expired_at\) > datetime\('now'\)/);
  assert.match(wallet, /datetime\(doku_expired_at\) <= datetime\('now'\)/);
});
