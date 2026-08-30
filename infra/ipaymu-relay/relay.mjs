import { createHmac, timingSafeEqual } from "node:crypto";

const PAYMENT_PATH = "/api/v2/payment/direct";
const MAX_BODY_BYTES = 64 * 1024;
const MAX_CLOCK_SKEW_SECONDS = 300;
const NONCE_TTL_MS = 10 * 60 * 1000;

function json(res, status, payload) {
  const body = JSON.stringify(payload);
  res.writeHead(status, {
    "content-type": "application/json; charset=utf-8",
    "content-length": Buffer.byteLength(body),
    "cache-control": "no-store",
    "x-content-type-options": "nosniff",
  });
  res.end(body);
}

function hmac(secret, value) {
  return createHmac("sha256", secret).update(value, "utf8").digest("hex");
}

function secureEqual(left, right) {
  if (!/^[a-f0-9]{64}$/i.test(left || "")) return false;
  const a = Buffer.from(left.toLowerCase(), "utf8");
  const b = Buffer.from(right.toLowerCase(), "utf8");
  return a.length === b.length && timingSafeEqual(a, b);
}

export function relaySignature(secret, { timestamp, nonce, method, path, body }) {
  return hmac(secret, `${timestamp}\n${nonce}\n${method.toUpperCase()}\n${path}\n${body}`);
}

async function readBody(req) {
  const chunks = [];
  let total = 0;
  for await (const chunk of req) {
    total += chunk.length;
    if (total > MAX_BODY_BYTES) {
      const error = new Error("Payload terlalu besar.");
      error.statusCode = 413;
      throw error;
    }
    chunks.push(chunk);
  }
  return Buffer.concat(chunks).toString("utf8");
}

export function createRelayHandler({ secret, upstreamOrigin = "https://my.ipaymu.com", fetchImpl = fetch, now = Date.now }) {
  if (!secret || secret.length < 32) throw new Error("RELAY_SHARED_SECRET minimal 32 karakter.");
  const upstream = new URL(upstreamOrigin);
  if (upstream.protocol !== "https:") throw new Error("RELAY_UPSTREAM_ORIGIN wajib memakai HTTPS.");
  const usedNonces = new Map();

  return async function relayHandler(req, res) {
    try {
      const requestUrl = new URL(req.url || "/", "http://relay.local");
      if (req.method === "GET" && requestUrl.pathname === "/healthz") {
        return json(res, 200, { ok: true, service: "lfamilia-ipaymu-relay" });
      }
      if (req.method !== "POST" || requestUrl.pathname !== PAYMENT_PATH || requestUrl.search) {
        return json(res, 404, { error: "Rute tidak tersedia." });
      }
      if (!String(req.headers["content-type"] || "").toLowerCase().startsWith("application/json")) {
        return json(res, 415, { error: "Content-Type wajib application/json." });
      }

      const rawBody = await readBody(req);
      const relayTimestamp = String(req.headers["x-lfamilia-relay-timestamp"] || "");
      const relayNonce = String(req.headers["x-lfamilia-relay-nonce"] || "");
      const receivedSignature = String(req.headers["x-lfamilia-relay-signature"] || "");
      const timestampNumber = Number(relayTimestamp);
      const nowMs = now();

      if (!Number.isInteger(timestampNumber) || Math.abs(Math.floor(nowMs / 1000) - timestampNumber) > MAX_CLOCK_SKEW_SECONDS) {
        return json(res, 401, { error: "Tanda waktu relay tidak valid." });
      }
      if (!/^[a-zA-Z0-9-]{16,128}$/.test(relayNonce)) {
        return json(res, 401, { error: "Nonce relay tidak valid." });
      }
      const expectedSignature = relaySignature(secret, {
        timestamp: relayTimestamp,
        nonce: relayNonce,
        method: "POST",
        path: PAYMENT_PATH,
        body: rawBody,
      });
      if (!secureEqual(receivedSignature, expectedSignature)) {
        return json(res, 401, { error: "Signature relay tidak valid." });
      }

      for (const [nonce, expiresAt] of usedNonces) {
        if (expiresAt <= nowMs) usedNonces.delete(nonce);
      }
      if (usedNonces.has(relayNonce)) return json(res, 409, { error: "Permintaan relay sudah pernah dipakai." });
      usedNonces.set(relayNonce, nowMs + NONCE_TTL_MS);

      try {
        const parsed = JSON.parse(rawBody);
        if (!parsed || typeof parsed !== "object" || Array.isArray(parsed)) throw new Error();
      } catch {
        return json(res, 400, { error: "Payload JSON tidak valid." });
      }

      const va = String(req.headers.va || "").trim();
      const ipaymuSignature = String(req.headers.signature || "").trim();
      const ipaymuTimestamp = String(req.headers.timestamp || "").trim();
      if (!va || !ipaymuSignature || !ipaymuTimestamp) {
        return json(res, 400, { error: "Header otorisasi iPaymu tidak lengkap." });
      }

      let upstreamResponse;
      try {
        upstreamResponse = await fetchImpl(new URL(PAYMENT_PATH, upstream), {
          method: "POST",
          headers: {
            "content-type": "application/json",
            accept: "application/json",
            va,
            signature: ipaymuSignature,
            timestamp: ipaymuTimestamp,
          },
          body: rawBody,
          redirect: "manual",
          signal: AbortSignal.timeout(15_000),
        });
      } catch {
        usedNonces.delete(relayNonce);
        return json(res, 502, { error: "Koneksi relay ke iPaymu gagal." });
      }

      const responseBody = Buffer.from(await upstreamResponse.arrayBuffer());
      res.writeHead(upstreamResponse.status, {
        "content-type": upstreamResponse.headers.get("content-type") || "application/json; charset=utf-8",
        "content-length": responseBody.length,
        "cache-control": "no-store",
        "x-content-type-options": "nosniff",
      });
      res.end(responseBody);
    } catch (error) {
      return json(res, Number(error?.statusCode) || 500, { error: "Relay tidak dapat memproses permintaan." });
    }
  };
}

