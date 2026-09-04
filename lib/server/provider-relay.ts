import { getRuntimeEnv } from "@/lib/server/runtime-env";

type ProviderRelayEnv = {
  PROVIDER_RELAY_TOKEN?: string;
  PROVIDER_RELAY_HOSTS?: string;
};

function relayHosts(value?: string) {
  return new Set(
    (value ?? "")
      .split(",")
      .map((item) => item.trim().toLowerCase())
      .filter(Boolean),
  );
}

export function withProviderRelayHeaders(
  url: string,
  headers: Record<string, string>,
) {
  const runtime = getRuntimeEnv<ProviderRelayEnv>();
  const token = runtime.PROVIDER_RELAY_TOKEN?.trim();
  if (!token) return headers;

  let hostname = "";
  try {
    hostname = new URL(url).hostname.toLowerCase();
  } catch {
    return headers;
  }

  if (!relayHosts(runtime.PROVIDER_RELAY_HOSTS).has(hostname)) return headers;

  return {
    ...headers,
    "x-lfamilia-relay-token": token,
  };
}
