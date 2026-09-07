type AccessEnvironment = {
  TEAM_DOMAIN?: string;
  POLICY_AUD?: string;
};

type AccessJwtHeader = {
  alg?: unknown;
  kid?: unknown;
};

type AccessJwtClaims = {
  aud?: unknown;
  email?: unknown;
  exp?: unknown;
  iat?: unknown;
  iss?: unknown;
  nbf?: unknown;
};

type AccessJwks = {
  keys?: JsonWebKey[];
};

type JwksCacheEntry = {
  expiresAt: number;
  keys: Map<string, JsonWebKey>;
};

type AccessFetch = (
  input: URL,
  init?: RequestInit,
) => Promise<Response>;

const CLOCK_SKEW_SECONDS = 30;
const JWKS_CACHE_MS = 5 * 60 * 1000;
const JWKS_FORCED_REFRESH_MS = 60 * 1000;
const jwksCache = new Map<string, JwksCacheEntry>();
const jwksForcedRefresh = new Map<string, number>();

function decodeBase64Url(value: string) {
  if (!/^[A-Za-z0-9_-]+$/.test(value)) {
    throw new Error("Segmen JWT tidak valid.");
  }

  const normalized = value.replaceAll("-", "+").replaceAll("_", "/");
  const padded = normalized.padEnd(
    normalized.length + ((4 - (normalized.length % 4)) % 4),
    "=",
  );
  const decoded = atob(padded);
  return Uint8Array.from(decoded, (character) => character.charCodeAt(0))
    .buffer;
}

function parseJwtSegment<T>(value: string): T {
  return JSON.parse(new TextDecoder().decode(decodeBase64Url(value))) as T;
}

function normalizeTeamDomain(value: string | undefined) {
  if (!value?.trim()) return null;

  try {
    const url = new URL(value.trim());
    if (
      url.protocol !== "https:" ||
      !url.hostname.endsWith(".cloudflareaccess.com")
    ) {
      return null;
    }
    return url.origin;
  } catch {
    return null;
  }
}

function hasAudience(value: unknown, expected: string) {
  if (typeof value === "string") return value === expected;
  return Array.isArray(value) && value.some((item) => item === expected);
}

async function fetchJwks(
  teamDomain: string,
  fetchAccess: AccessFetch,
): Promise<JwksCacheEntry> {
  const response = await fetchAccess(
    new URL("/cdn-cgi/access/certs", teamDomain),
    {
      headers: { accept: "application/json" },
      redirect: "error",
      signal: AbortSignal.timeout(5_000),
    },
  );
  if (!response.ok) {
    throw new Error("Cloudflare Access JWKS tidak tersedia.");
  }

  const payload = (await response.json()) as AccessJwks;
  if (!Array.isArray(payload.keys)) {
    throw new Error("Cloudflare Access JWKS tidak valid.");
  }

  const keys = new Map<string, JsonWebKey>();
  for (const key of payload.keys) {
    if (
      key &&
      typeof key === "object" &&
      typeof key.kid === "string" &&
      key.kid
    ) {
      keys.set(key.kid, key);
    }
  }
  if (!keys.size) {
    throw new Error("Cloudflare Access JWKS tidak memiliki signing key.");
  }

  const entry = {
    expiresAt: Date.now() + JWKS_CACHE_MS,
    keys,
  };
  jwksCache.set(teamDomain, entry);
  return entry;
}

async function getSigningKey(
  teamDomain: string,
  kid: string,
  fetchAccess: AccessFetch,
) {
  const now = Date.now();
  let entry = jwksCache.get(teamDomain);
  let refreshed = false;
  if (!entry || entry.expiresAt <= now) {
    entry = await fetchJwks(teamDomain, fetchAccess);
    refreshed = true;
  }

  let key = entry.keys.get(kid);
  const lastForcedRefresh = jwksForcedRefresh.get(teamDomain) ?? 0;
  if (
    !key &&
    !refreshed &&
    now - lastForcedRefresh >= JWKS_FORCED_REFRESH_MS
  ) {
    jwksForcedRefresh.set(teamDomain, now);
    entry = await fetchJwks(teamDomain, fetchAccess);
    key = entry.keys.get(kid);
  }
  return key ?? null;
}

export async function verifyCloudflareAccess(
  request: Request,
  env: AccessEnvironment,
  fetchAccess: AccessFetch = (input, init) => fetch(input, init),
): Promise<{ email: string } | null> {
  try {
    const assertion = request.headers.get("cf-access-jwt-assertion")?.trim();
    const teamDomain = normalizeTeamDomain(env.TEAM_DOMAIN);
    const audience = env.POLICY_AUD?.trim();
    if (!assertion || !teamDomain || !audience) return null;

    const segments = assertion.split(".");
    if (segments.length !== 3 || segments.some((segment) => !segment)) {
      return null;
    }

    const [encodedHeader, encodedClaims, encodedSignature] = segments;
    const header = parseJwtSegment<AccessJwtHeader>(encodedHeader);
    const claims = parseJwtSegment<AccessJwtClaims>(encodedClaims);
    if (
      header.alg !== "RS256" ||
      typeof header.kid !== "string" ||
      !header.kid
    ) {
      return null;
    }

    const signingKey = await getSigningKey(
      teamDomain,
      header.kid,
      fetchAccess,
    );
    if (!signingKey) return null;

    const publicKey = await crypto.subtle.importKey(
      "jwk",
      signingKey,
      {
        name: "RSASSA-PKCS1-v1_5",
        hash: "SHA-256",
      },
      false,
      ["verify"],
    );
    const signatureValid = await crypto.subtle.verify(
      "RSASSA-PKCS1-v1_5",
      publicKey,
      decodeBase64Url(encodedSignature),
      new TextEncoder().encode(`${encodedHeader}.${encodedClaims}`),
    );
    if (!signatureValid) return null;

    const now = Math.floor(Date.now() / 1000);
    const issuer =
      typeof claims.iss === "string"
        ? claims.iss.replace(/\/$/, "")
        : "";
    if (
      issuer !== teamDomain ||
      !hasAudience(claims.aud, audience) ||
      typeof claims.exp !== "number" ||
      claims.exp <= now - CLOCK_SKEW_SECONDS ||
      (typeof claims.nbf === "number" &&
        claims.nbf > now + CLOCK_SKEW_SECONDS) ||
      (typeof claims.iat === "number" &&
        claims.iat > now + CLOCK_SKEW_SECONDS)
    ) {
      return null;
    }

    if (
      typeof claims.email !== "string" ||
      !claims.email.includes("@") ||
      claims.email.length > 320
    ) {
      return null;
    }

    return { email: claims.email.trim().toLowerCase() };
  } catch {
    return null;
  }
}
