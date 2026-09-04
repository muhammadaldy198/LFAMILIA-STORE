import { createServer } from "node:http";
import { timingSafeEqual } from "node:crypto";

const PORT = Number(process.env.PORT || 8788);
const HOST = process.env.HOST || "127.0.0.1";
const MAX_BODY_BYTES = Number(process.env.MAX_BODY_BYTES || 1_048_576);
const UPSTREAM_TIMEOUT_MS = Number(process.env.UPSTREAM_TIMEOUT_MS || 20_000);

const relayToken = (process.env.RELAY_TOKEN || "").trim();

const providers = new Map([
  [
    (process.env.DIGIFLAZZ_RELAY_HOST || "digiflazz-relay.lfamiliastore.my.id").toLowerCase(),
    process.env.DIGIFLAZZ_UPSTREAM_ORIGIN || "https://api.digiflazz.com",
  ],
  [
    (process.env.IPAYMU_RELAY_HOST || "ipaymu-relay.lfamiliastore.my.id").toLowerCase(),
    process.env.IPAYMU_UPSTREAM_ORIGIN || "",
  ],
  [
    (process.env.MIDTRANS_BISNAP_RELAY_HOST || "bisnap-relay.lfamiliastore.my.id").toLowerCase(),
    process.env.MIDTRANS_BISNAP_UPSTREAM_ORIGIN || "",
  ],
]);

const hopByHopHeaders = new Set([
  "connection",
  "content-length",
  "host",
  "keep-alive",
  "proxy-authenticate",
  "proxy-authorization",
  "te",
  "trailer",
  "transfer-encoding",
  "upgrade",
  "cookie",
  "x-lfamilia-relay-token",
]);

function json(res, status, payload) {
  const body = Buffer.from(JSON.stringify(payload));
  res.writeHead(status, {
    "content-type": "application/json; charset=utf-8",
    "content-length": String(body.length),
    "cache-control": "no-store",
  });
  res.end(body);
}

function safeTokenEqual(received) {
  if (!relayToken || !received) return false;
  const expectedBuffer = Buffer.from(relayToken);
  const receivedBuffer = Buffer.from(String(received));
  if (expectedBuffer.length !== receivedBuffer.length) return false;
  return timingSafeEqual(expectedBuffer, receivedBuffer);
}

function requestHostname(req) {
  return String(req.headers.host || "")
    .split(":")[0]
    .trim()
    .toLowerCase();
}

function buildUpstreamUrl(req, upstreamOrigin) {
  const incoming = new URL(req.url || "/", "http://relay.invalid");
  const upstream = new URL(upstreamOrigin);
  upstream.pathname = incoming.pathname;
  upstream.search = incoming.search;
  upstream.hash = "";
  return upstream;
}

function forwardHeaders(req) {
  const headers = new Headers();
  for (const [name, rawValue] of Object.entries(req.headers)) {
    const lower = name.toLowerCase();
    if (
      hopByHopHeaders.has(lower) ||
      lower.startsWith("cf-") ||
      lower.startsWith("x-forwarded-")
    ) {
      continue;
    }

    const values = Array.isArray(rawValue) ? rawValue : [rawValue];
    for (const value of values) {
      if (value != null) headers.append(name, value);
    }
  }
  return headers;
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

  return Buffer.concat(chunks);
}

function responseHeaders(upstream) {
  const headers = {};
  for (const name of [
    "content-type",
    "cache-control",
    "date",
    "x-request-id",
    "x-correlation-id",
  ]) {
    const value = upstream.headers.get(name);
    if (value) headers[name] = value;
  }
  headers["cache-control"] = "no-store";
  return headers;
}

const server = createServer(async (req, res) => {
  const startedAt = Date.now();
  const hostname = requestHostname(req);

  if (req.method === "GET" && req.url === "/health") {
    json(res, 200, {
      ok: true,
      service: "lfamilia-provider-relay",
      configured: {
        digiflazz: Boolean(providers.get(process.env.DIGIFLAZZ_RELAY_HOST?.toLowerCase() || "digiflazz-relay.lfamiliastore.my.id")),
        ipaymu: Boolean(process.env.IPAYMU_UPSTREAM_ORIGIN),
        midtransBisnap: Boolean(process.env.MIDTRANS_BISNAP_UPSTREAM_ORIGIN),
      },
    });
    return;
  }

  if (req.method !== "POST") {
    json(res, 405, { error: "Method tidak diizinkan." });
    return;
  }

  if (!safeTokenEqual(req.headers["x-lfamilia-relay-token"])) {
    json(res, 401, { error: "Relay token tidak valid." });
    return;
  }

  const upstreamOrigin = providers.get(hostname);
  if (!upstreamOrigin) {
    json(res, providers.has(hostname) ? 503 : 404, {
      error: providers.has(hostname)
        ? "Upstream provider belum dikonfigurasi."
        : "Host relay tidak dikenal.",
    });
    return;
  }

  try {
    const body = await readBody(req);
    const target = buildUpstreamUrl(req, upstreamOrigin);
    const upstream = await fetch(target, {
      method: "POST",
      headers: forwardHeaders(req),
      body,
      redirect: "manual",
      signal: AbortSignal.timeout(UPSTREAM_TIMEOUT_MS),
    });

    const responseBody = Buffer.from(await upstream.arrayBuffer());
    res.writeHead(upstream.status, {
      ...responseHeaders(upstream),
      "content-length": String(responseBody.length),
    });
    res.end(responseBody);

    process.stdout.write(
      JSON.stringify({
        time: new Date().toISOString(),
        host: hostname,
        path: new URL(req.url || "/", "http://relay.invalid").pathname,
        status: upstream.status,
        elapsedMs: Date.now() - startedAt,
      }) + "\n",
    );
  } catch (error) {
    const status = Number(error?.statusCode) || 502;
    json(res, status, {
      error:
        status === 413
          ? "Payload terlalu besar."
          : "Relay gagal menghubungi provider.",
    });

    process.stderr.write(
      JSON.stringify({
        time: new Date().toISOString(),
        host: hostname,
        path: new URL(req.url || "/", "http://relay.invalid").pathname,
        error: error instanceof Error ? error.name : "UnknownError",
        elapsedMs: Date.now() - startedAt,
      }) + "\n",
    );
  }
});

server.requestTimeout = UPSTREAM_TIMEOUT_MS + 5_000;
server.headersTimeout = 10_000;
server.listen(PORT, HOST, () => {
  process.stdout.write(
    `LFAMILIA provider relay listening on http://${HOST}:${PORT}\n`,
  );
});
