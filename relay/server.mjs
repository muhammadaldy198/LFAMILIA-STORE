import { createServer } from "node:http";
import { timingSafeEqual } from "node:crypto";

function requireEnv(name) {
  const value = process.env[name]?.trim();
  if (!value) throw new Error(`Environment ${name} wajib diisi.`);
  return value;
}

function optionalEnv(name) {
  return process.env[name]?.trim() || "";
}

function requirePositiveInt(name) {
  const value = Number(requireEnv(name));
  if (!Number.isInteger(value) || value <= 0) {
    throw new Error(`Environment ${name} harus berupa integer positif.`);
  }
  return value;
}

function normalizeOrigin(value, name) {
  if (!value) return "";
  const url = new URL(value);
  if (url.protocol !== "https:") {
    throw new Error(`${name} wajib menggunakan HTTPS.`);
  }
  url.pathname = "/";
  url.search = "";
  url.hash = "";
  return url.origin;
}

const HOST = requireEnv("RELAY_BIND_HOST");
const PORT = requirePositiveInt("RELAY_BIND_PORT");
const MAX_BODY_BYTES = requirePositiveInt("RELAY_MAX_BODY_BYTES");
const UPSTREAM_TIMEOUT_MS = requirePositiveInt("RELAY_UPSTREAM_TIMEOUT_MS");
const relayToken = requireEnv("RELAY_TOKEN");

const providerDefinitions = [
  {
    name: "digiflazz",
    host: optionalEnv("DIGIFLAZZ_RELAY_HOST").toLowerCase(),
    developmentUpstream: normalizeOrigin(
      optionalEnv("DIGIFLAZZ_DEVELOPMENT_UPSTREAM_ORIGIN"),
      "DIGIFLAZZ_DEVELOPMENT_UPSTREAM_ORIGIN",
    ),
    productionUpstream: normalizeOrigin(
      optionalEnv("DIGIFLAZZ_PRODUCTION_UPSTREAM_ORIGIN"),
      "DIGIFLAZZ_PRODUCTION_UPSTREAM_ORIGIN",
    ),
  },
  {
    name: "ipaymu",
    host: optionalEnv("IPAYMU_RELAY_HOST").toLowerCase(),
    sandboxUpstream: normalizeOrigin(
      optionalEnv("IPAYMU_SANDBOX_UPSTREAM_ORIGIN"),
      "IPAYMU_SANDBOX_UPSTREAM_ORIGIN",
    ),
    productionUpstream: normalizeOrigin(
      optionalEnv("IPAYMU_PRODUCTION_UPSTREAM_ORIGIN"),
      "IPAYMU_PRODUCTION_UPSTREAM_ORIGIN",
    ),
  },
 ];

const providers = new Map(
  providerDefinitions
    .filter((provider) => provider.host)
    .map((provider) => [provider.host, provider]),
);

const internalHeaders = new Set([
  "x-lfamilia-relay-token",
  "x-lfamilia-digiflazz-environment",
  "x-lfamilia-ipaymu-environment",
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
  ...internalHeaders,
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
  if (!received) return false;
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
  const incoming = new URL(req.url || "/", "http://relay.local");
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

function isMethodAllowed(_provider, method) {
  return method === "POST";
}

function resolveProviderUpstream(provider, req) {
  if (provider.name === "digiflazz") {
    const environment = String(
      req.headers["x-lfamilia-digiflazz-environment"] || "",
    ).toLowerCase();

    if (environment === "development") return provider.developmentUpstream;
    if (environment === "production") return provider.productionUpstream;
    return "";
  }

  if (provider.name === "ipaymu") {
    const environment = String(
      req.headers["x-lfamilia-ipaymu-environment"] || "",
    ).toLowerCase();

    if (environment === "sandbox") return provider.sandboxUpstream;
    if (environment === "production") return provider.productionUpstream;
    return "";
  }

  return "";
}

function providerConfigured(provider) {
  if (provider.name === "digiflazz") {
    return Boolean(
      provider.host &&
        provider.developmentUpstream &&
        provider.productionUpstream,
    );
  }

  if (provider.name === "ipaymu") {
    return Boolean(
      provider.host &&
        provider.sandboxUpstream &&
        provider.productionUpstream,
    );
  }

  return Boolean(
    provider.host &&
      provider.sandboxUpstream &&
      provider.productionUpstream,
  );
}

const server = createServer(async (req, res) => {
  const startedAt = Date.now();
  const hostname = requestHostname(req);

  if (req.method === "GET" && req.url === "/health") {
    json(res, 200, {
      ok: true,
      service: "lfamilia-provider-relay",
      configured: Object.fromEntries(
        providerDefinitions.map((provider) => [
          provider.name,
          providerConfigured(provider),
        ]),
      ),
    });
    return;
  }

  if (!safeTokenEqual(req.headers["x-lfamilia-relay-token"])) {
    json(res, 401, { error: "Relay token tidak valid." });
    return;
  }

  const provider = providers.get(hostname);
  if (!provider) {
    json(res, 404, { error: "Host relay tidak dikenal." });
    return;
  }

  if (!isMethodAllowed(provider, req.method)) {
    json(res, 405, { error: "Method tidak diizinkan untuk provider ini." });
    return;
  }

  const providerUpstream = resolveProviderUpstream(provider, req);
  if (!providerUpstream) {
    json(res, 503, {
      error: "Environment atau upstream provider belum dikonfigurasi.",
    });
    return;
  }

  try {
    const body = req.method === "GET" ? undefined : await readBody(req);
    const target = buildUpstreamUrl(req, providerUpstream);
    const upstream = await fetch(target, {
      method: req.method,
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
        provider: provider.name,
        path: new URL(req.url || "/", "http://relay.local").pathname,
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
        provider: provider.name,
        path: new URL(req.url || "/", "http://relay.local").pathname,
        error: error instanceof Error ? error.name : "UnknownError",
        elapsedMs: Date.now() - startedAt,
      }) + "\n",
    );
  }
});

server.requestTimeout =
  UPSTREAM_TIMEOUT_MS + requirePositiveInt("RELAY_REQUEST_TIMEOUT_BUFFER_MS");
server.headersTimeout = requirePositiveInt("RELAY_HEADERS_TIMEOUT_MS");
server.listen(PORT, HOST, () => {
  process.stdout.write(
    `LFAMILIA provider relay listening on http://${HOST}:${PORT}\n`,
  );
});
