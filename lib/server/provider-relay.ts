import { getRuntimeEnv } from "@/lib/server/runtime-env";

type ProviderRelayEnv = {
  PROVIDER_RELAY_TOKEN?: string;
  PROVIDER_RELAY_HOSTS?: string;
  PROVIDER_RELAY_DIGIFLAZZ_ORIGIN?: string;
  PROVIDER_RELAY_IPAYMU_ORIGIN?: string;
  PROVIDER_RELAY_MIDTRANS_BISNAP_ORIGIN?: string;
};

export type RelayProvider = "digiflazz" | "ipaymu" | "midtrans-bisnap";

function relayHosts(value?: string) {
  return (value ?? "")
    .split(",")
    .map((item) => item.trim())
    .filter(Boolean);
}

function legacyOriginFor(provider: RelayProvider, hosts?: string) {
  const candidates = relayHosts(hosts);
  const selected = candidates.find((host) => {
    const value = host.toLowerCase();
    if (provider === "digiflazz") return value.includes("digiflazz");
    if (provider === "ipaymu") return value.includes("ipaymu");
    return value.includes("bisnap") || value.includes("midtrans");
  });
  if (!selected) return "";
  return selected.startsWith("https://") ? selected : `https://${selected}`;
}

function configuredOrigin(runtime: ProviderRelayEnv, provider: RelayProvider) {
  const explicit =
    provider === "digiflazz"
      ? runtime.PROVIDER_RELAY_DIGIFLAZZ_ORIGIN
      : provider === "ipaymu"
        ? runtime.PROVIDER_RELAY_IPAYMU_ORIGIN
        : runtime.PROVIDER_RELAY_MIDTRANS_BISNAP_ORIGIN;
  return explicit?.trim() || legacyOriginFor(provider, runtime.PROVIDER_RELAY_HOSTS);
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
  return {
    url: routedUrl,
    headers: {
      ...headers,
      "x-lfamilia-relay-token": token,
      [`x-lfamilia-${route.provider}-environment`]: route.environment,
    },
    relayed: true,
  };
}

// Backward-compatible helper for code that only needs headers.
export function withProviderRelayHeaders(
  url: string,
  headers: Record<string, string>,
  route?: { provider: RelayProvider; environment: string },
) {
  if (!route) return headers;
  return providerRelayRequest(url, headers, route).headers;
}
