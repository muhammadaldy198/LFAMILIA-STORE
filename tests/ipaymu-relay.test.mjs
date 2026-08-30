import assert from "node:assert/strict";
import { createServer } from "node:http";
import test from "node:test";
import { createRelayHandler, relaySignature } from "../infra/ipaymu-relay/relay.mjs";

const secret = "test-secret-with-at-least-thirty-two-characters";

async function startRelay(fetchImpl) {
  const now = 1_800_000_000_000;
  const handler = createRelayHandler({ secret, fetchImpl, now: () => now });
  const server = createServer((req, res) => void handler(req, res));
  await new Promise((resolve) => server.listen(0, "127.0.0.1", resolve));
  const address = server.address();
  return {
    now,
    url: `http://127.0.0.1:${address.port}`,
    close: () => new Promise((resolve, reject) => server.close((error) => error ? reject(error) : resolve())),
  };
}

function signedHeaders(body, now, nonce = "relay-test-nonce-0001") {
  const timestamp = Math.floor(now / 1000).toString();
  return {
    "content-type": "application/json",
    va: "1179001234567890",
    signature: "ipaymu-signature",
    timestamp: "20260829010101",
    "x-lfamilia-relay-timestamp": timestamp,
    "x-lfamilia-relay-nonce": nonce,
    "x-lfamilia-relay-signature": relaySignature(secret, {
      timestamp,
      nonce,
      method: "POST",
      path: "/api/v2/payment/direct",
      body,
    }),
  };
}

test("relay meneruskan request bertanda tangan dan menolak replay", async () => {
  let forwarded;
  const relay = await startRelay(async (url, init) => {
    forwarded = { url: String(url), init };
    return Response.json({ Success: true, Data: { TransactionId: "123" } });
  });
  const body = JSON.stringify({ amount: 10_000, referenceId: "LF-TEST" });
  const headers = signedHeaders(body, relay.now);

  try {
    const response = await fetch(`${relay.url}/api/v2/payment/direct`, { method: "POST", headers, body });
    assert.equal(response.status, 200);
    assert.equal(forwarded.url, "https://my.ipaymu.com/api/v2/payment/direct");
    assert.equal(forwarded.init.body, body);
    assert.equal(forwarded.init.headers.va, "1179001234567890");

    const replay = await fetch(`${relay.url}/api/v2/payment/direct`, { method: "POST", headers, body });
    assert.equal(replay.status, 409);
  } finally {
    await relay.close();
  }
});

test("relay menolak request tanpa signature LFAMILIA", async () => {
  const relay = await startRelay(() => { throw new Error("tidak boleh diteruskan"); });
  try {
    const response = await fetch(`${relay.url}/api/v2/payment/direct`, {
      method: "POST",
      headers: { "content-type": "application/json" },
      body: "{}",
    });
    assert.equal(response.status, 401);
  } finally {
    await relay.close();
  }
});

