import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";
import { registerHooks } from "node:module";
import { pathToFileURL } from "node:url";
import test from "node:test";

const root = process.cwd();

registerHooks({
  resolve(specifier, context, nextResolve) {
    if (specifier.startsWith("@/")) {
      const relative = specifier.slice(2);
      const candidates = [
        `${relative}.ts`,
        `${relative}.tsx`,
        path.join(relative, "index.ts"),
        path.join(relative, "index.tsx"),
      ];
      for (const candidate of candidates) {
        const absolute = path.join(root, candidate);
        if (fs.existsSync(absolute)) {
          return { url: pathToFileURL(absolute).href, shortCircuit: true };
        }
      }
    }
    return nextResolve(specifier, context);
  },
});

const { parseDokuVaStatusPayload } = await import("../lib/server/doku-status.ts");

test("single DOKU VA status payload maps paid amount normally", () => {
  const result = parseDokuVaStatusPayload({
    virtualAccountData: {
      trxId: "LF-ONE",
      paymentFlagStatus: "00",
      paidAmount: { value: "15000.00", currency: "IDR" },
    },
  }, "LF-ONE");
  assert.deepEqual(result, { status: "paid", amount: 15000 });
});

test("DOKU VA status array selects only the matching transaction reference", () => {
  const result = parseDokuVaStatusPayload({
    virtualAccountData: [
      {
        trxId: "OLDER",
        paymentFlagStatus: "00",
        paidAmount: { value: "25000.00", currency: "IDR" },
      },
      {
        trxId: "TARGET",
        paymentFlagStatus: "00",
        paidAmount: { value: "17000.00", currency: "IDR" },
      },
    ],
  }, "TARGET");
  assert.deepEqual(result, { status: "paid", amount: 17000 });
});

test("ambiguous VA status arrays fail closed instead of crediting another transaction", () => {
  const result = parseDokuVaStatusPayload({
    virtualAccountData: [
      { paymentFlagStatus: "00", paidAmount: { value: "10000.00" } },
      { paymentFlagStatus: "00", paidAmount: { value: "10000.00" } },
    ],
  }, "TARGET");
  assert.deepEqual(result, { status: "pending", amount: 0 });
});

test("DOKU VA success reason with a positive paid amount remains compatible", () => {
  const result = parseDokuVaStatusPayload({
    virtualAccountData: {
      trxId: "TARGET",
      paymentFlagStatus: "03",
      paymentFlagReason: { english: "SUCCESS" },
      paidAmount: { value: "9000.00" },
    },
  }, "TARGET");
  assert.deepEqual(result, { status: "paid", amount: 9000 });
});
