import { getRuntimeEnv } from "@/lib/server/runtime-env";

type ProviderRelayEnv = {
  PROVIDER_RELAY_TOKEN?: string;
  PROVIDER_RELAY_HOSTS?: string;
  PROVIDER_RELAY_DIGIFLAZZ_ORIGIN?: string;
  PROVIDER_RELAY_MIDTRANS_ORIGIN?: string;
};

export type RelayProvider = "digiflazz" | "midtrans" | "melostore";

function relayHosts(value?: string) {
  return (value ?? "")
    .split(",")
    .map((item) => item.trim())
    .filter(Boolean);
}

function legacyOriginFor(provider: "digiflazz" | "midtrans", hosts?: string) {
  const candidates = relayHosts(hosts);
  const selected = candidates.find((host) => host.toLowerCase().includes(provider));
  if (!selected) return "";
  return selected.startsWith("https://") ? selected : `https://${selected}`;
}

function configuredOrigin(runtime: ProviderRelayEnv, provider: RelayProvider) {
  if (provider === "digiflazz") {
    return runtime.PROVIDER_RELAY_DIGIFLAZZ_ORIGIN?.trim()
      || legacyOriginFor("digiflazz", runtime.PROVIDER_RELAY_HOSTS);
  }
  if (provider === "midtrans") {
    return runtime.PROVIDER_RELAY_MIDTRANS_ORIGIN?.trim()
      || legacyOriginFor("midtrans", runtime.PROVIDER_RELAY_HOSTS);
  }

  // Nickname lookup only reuses the existing authenticated VPS transport.
  // It does not make Melostore a fulfillment provider and does not expose a
  // generic Melostore proxy. The VPS allowlists only check-nickname.
  return runtime.PROVIDER_RELAY_DIGIFLAZZ_ORIGIN?.trim()
    || legacyOriginFor("digiflazz", runtime.PROVIDER_RELAY_HOSTS);
}

function routeUrl(originalUrl: string, relayOrigin: string) {
  const source = new URL(originalUrl);
  const relay = new URL(relayOrigin);
  if (relay.protocol !== "https:") throw new Error("URL VPS Relay wajib menggunakan HTTPS.");
  relay.pathname = source.pathname;
  relay.search = source.search;
  relay.hash = "";
  return relay.toString();
}

export function isProviderRelayConfigured(provider: RelayProvider) {
  const runtime = getRuntimeEnv<ProviderRelayEnv>();
  return Boolean(
    runtime.PROVIDER_RELAY_TOKEN?.trim() &&
    configuredOrigin(runtime, provider),
  );
}

export function providerRelayRequest(
  url: string,
  headers: Record<string, string>,
  route: { provider: RelayProvider; environment: string },
) {
  const runtime = getRuntimeEnv<ProviderRelayEnv>();
  const token = runtime.PROVIDER_RELAY_TOKEN?.trim();
  const relayOrigin = configuredOrigin(runtime, route.provider);
  if (!token || !relayOrigin) return { url, headers, relayed: false };

  const routedUrl = routeUrl(url, relayOrigin);
  const relayHeaders: Record<string, string> = {
    ...headers,
    "x-lfamilia-relay-token": token,
    [`x-lfamilia-${route.provider}-environment`]: route.environment,
  };

  // The nickname service intentionally shares the existing relay hostname.
  // This authenticated internal selector is stripped by the VPS before the
  // request is forwarded upstream.
  if (route.provider === "melostore") {
    relayHeaders["x-lfamilia-relay-provider"] = "melostore";
  }

  return {
    url: routedUrl,
    headers: relayHeaders,
    relayed: true,
  };
}

export type RelayConnectionResult = {
  provider: RelayProvider;
  label: string;
  connected: boolean;
  status: number | null;
  message: string;
};

export async function testRelayConnection(
  provider: RelayProvider,
  label: string,
): Promise<RelayConnectionResult> {
  const runtime = getRuntimeEnv<ProviderRelayEnv>();
  const token = runtime.PROVIDER_RELAY_TOKEN?.trim();
  const origin = configuredOrigin(runtime, provider);

  if (!origin) {
    return { provider, label, connected: false, status: null, message: "URL relay belum diisi." };
  }
  if (!token) {
    return { provider, label, connected: false, status: null, message: "Relay Token belum diisi." };
  }

  let parsed: URL;
  try {
    parsed = new URL(origin);
    if (parsed.protocol !== "https:") throw new Error("HTTPS required");
  } catch {
    return { provider, label, connected: false, status: null, message: "URL relay tidak valid atau bukan HTTPS." };
  }

  try {
    const healthResponse = await fetch(new URL("/health", parsed.origin), {
      method: "GET",
      headers: { accept: "application/json" },
      cache: "no-store",
      signal: AbortSignal.timeout(8_000),
    });
    const health = await healthResponse.json().catch(() => null) as {
      ok?: boolean;
      configured?: Record<string, boolean>;
    } | null;

    if (!healthResponse.ok || health?.ok !== true) {
      return {
        provider,
        label,
        connected: false,
        status: healthResponse.status,
        message: "Health relay gagal.",
      };
    }

    if (health.configured?.[provider] !== true) {
      return {
        provider,
        label,
        connected: false,
        status: healthResponse.status,
        message: "Upstream provider pada VPS belum lengkap.",
      };
    }

    const authHeaders: Record<string, string> = {
      "x-lfamilia-relay-token": token,
    };
    if (provider === "melostore") {
      authHeaders["x-lfamilia-relay-provider"] = "melostore";
    }

    const authResponse = await fetch(parsed.origin, {
      method: "HEAD",
      headers: authHeaders,
      cache: "no-store",
      redirect: "manual",
      signal: AbortSignal.timeout(8_000),
    });

    if (authResponse.status === 405) {
      return { provider, label, connected: true, status: 405, message: "Connected" };
    }
    if (authResponse.status === 401) {
      return { provider, label, connected: false, status: 401, message: "Relay Token tidak cocok dengan VPS." };
    }
    if (authResponse.status === 404) {
      return { provider, label, connected: false, status: 404, message: "Hostname/provider relay tidak dikenal oleh VPS." };
    }

    return {
      provider,
      label,
      connected: false,
      status: authResponse.status,
      message: `Relay merespons HTTP ${authResponse.status}; token/host belum terverifikasi.`,
    };
  } catch (error) {
    return {
      provider,
      label,
      connected: false,
      status: null,
      message: error instanceof Error && error.name === "TimeoutError"
        ? "Koneksi relay timeout."
        : "Worker tidak dapat menghubungi relay.",
    };
  }
}

const relayProbeCache = new Map<
  RelayProvider,
  { expiresAt: number; result: RelayConnectionResult }
>();

export async function probeProviderRelay(
  provider: RelayProvider,
  label: string,
  ttlMs = 30_000,
) {
  const cached = relayProbeCache.get(provider);
  if (cached && cached.expiresAt > Date.now()) return cached.result;

  const result = await testRelayConnection(provider, label);
  relayProbeCache.set(provider, {
    expiresAt: Date.now() + Math.max(1_000, ttlMs),
    result,
  });
  return result;
}

export async function testProviderRelayConnections() {
  return Promise.all([
    testRelayConnection("digiflazz", "DigiFlazz"),
    testRelayConnection("midtrans", "Midtrans BI-SNAP"),
    testRelayConnection("melostore", "Nickname Verification"),
  ]);
}

export function withProviderRelayHeaders(
  url: string,
  headers: Record<string, string>,
  route?: { provider: RelayProvider; environment: string },
) {
  if (!route) return headers;
  return providerRelayRequest(url, headers, route).headers;
}
