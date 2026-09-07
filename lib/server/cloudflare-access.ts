import {
  createLocalJWKSet,
  createRemoteJWKSet,
  jwtVerify,
  type JSONWebKeySet,
} from "jose";

type AccessEnvironment = {
  TEAM_DOMAIN?: string;
  POLICY_AUD?: string;
};

type AccessJwtClaims = {
  aud?: unknown;
  email?: unknown;
  exp?: unknown;
  iat?: unknown;
  iss?: unknown;
  nbf?: unknown;
};

type AccessFetch = (
  input: URL,
  init?: RequestInit,
) => Promise<Response>;

const CLOCK_SKEW_SECONDS = 30;
const remoteJwks = new Map<
  string,
  ReturnType<typeof createRemoteJWKSet>
>();

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

function readCookie(request: Request, name: string) {
  const cookieHeader = request.headers.get("cookie");
  if (!cookieHeader) return null;

  for (const pair of cookieHeader.split(";")) {
    const separator = pair.indexOf("=");
    if (separator < 0) continue;
    const key = pair.slice(0, separator).trim();
    if (key !== name) continue;
    const value = pair.slice(separator + 1).trim();
    return value || null;
  }
  return null;
}

export function getCloudflareAccessAssertion(request: Request) {
  return (
    request.headers.get("cf-access-jwt-assertion")?.trim() ||
    readCookie(request, "CF_Authorization")?.trim() ||
    null
  );
}

export function getCloudflareAccessConfigStatus(env: AccessEnvironment) {
  return {
    teamDomainConfigured: Boolean(normalizeTeamDomain(env.TEAM_DOMAIN)),
    audienceConfigured: Boolean(env.POLICY_AUD?.trim()),
  };
}

export function diagnoseCloudflareAccessRequest(
  request: Request,
  env: AccessEnvironment,
) {
  const assertion = getCloudflareAccessAssertion(request);
  const teamDomain = normalizeTeamDomain(env.TEAM_DOMAIN);
  const audience = env.POLICY_AUD?.trim();

  if (!teamDomain) return { reason: "TEAM_DOMAIN_INVALID" as const };
  if (!audience) return { reason: "POLICY_AUD_MISSING" as const };
  if (!assertion) return { reason: "ACCESS_TOKEN_MISSING" as const };

  try {
    const segments = assertion.split(".");
    if (segments.length !== 3 || segments.some((segment) => !segment)) {
      return { reason: "ACCESS_TOKEN_MALFORMED" as const };
    }

    const claims = parseJwtSegment<AccessJwtClaims>(segments[1]);
    const issuer =
      typeof claims.iss === "string"
        ? claims.iss.replace(/\/$/, "")
        : "";
    if (issuer !== teamDomain) {
      return {
        reason: "ACCESS_ISSUER_MISMATCH" as const,
        receivedIssuer: issuer || "(missing)",
        expectedIssuer: teamDomain,
      };
    }

    if (!hasAudience(claims.aud, audience)) {
      const receivedAudience =
        typeof claims.aud === "string"
          ? claims.aud
          : Array.isArray(claims.aud)
            ? claims.aud
                .filter((item): item is string => typeof item === "string")
                .join(", ")
            : "(missing)";
      return {
        reason: "ACCESS_AUDIENCE_MISMATCH" as const,
        receivedAudience,
        expectedAudience: audience,
      };
    }

    const now = Math.floor(Date.now() / 1000);
    if (
      typeof claims.exp !== "number" ||
      claims.exp <= now - CLOCK_SKEW_SECONDS
    ) {
      return { reason: "ACCESS_TOKEN_EXPIRED" as const };
    }
    if (
      (typeof claims.nbf === "number" &&
        claims.nbf > now + CLOCK_SKEW_SECONDS) ||
      (typeof claims.iat === "number" &&
        claims.iat > now + CLOCK_SKEW_SECONDS)
    ) {
      return { reason: "ACCESS_TOKEN_TIME_INVALID" as const };
    }

    if (
      typeof claims.email !== "string" ||
      !claims.email.includes("@") ||
      claims.email.length > 320
    ) {
      return { reason: "ACCESS_EMAIL_MISSING" as const };
    }

    return { reason: "ACCESS_SIGNATURE_OR_JWKS_INVALID" as const };
  } catch {
    return { reason: "ACCESS_TOKEN_MALFORMED" as const };
  }
}

async function getJwks(
  teamDomain: string,
  fetchAccess?: AccessFetch,
) {
  const certsUrl = new URL("/cdn-cgi/access/certs", teamDomain);

  if (fetchAccess) {
    const response = await fetchAccess(certsUrl, {
      headers: { accept: "application/json" },
      redirect: "error",
      signal: AbortSignal.timeout(5_000),
    });
    if (!response.ok) {
      throw new Error("Cloudflare Access JWKS tidak tersedia.");
    }
    const payload = (await response.json()) as JSONWebKeySet;
    if (!Array.isArray(payload.keys) || payload.keys.length === 0) {
      throw new Error("Cloudflare Access JWKS tidak valid.");
    }
    return createLocalJWKSet(payload);
  }

  let jwks = remoteJwks.get(teamDomain);
  if (!jwks) {
    jwks = createRemoteJWKSet(certsUrl, {
      timeoutDuration: 5_000,
      cooldownDuration: 30_000,
      cacheMaxAge: 5 * 60 * 1000,
      headers: { accept: "application/json" },
    });
    remoteJwks.set(teamDomain, jwks);
  }
  return jwks;
}

export async function verifyCloudflareAccess(
  request: Request,
  env: AccessEnvironment,
  fetchAccess?: AccessFetch,
): Promise<{ email: string } | null> {
  try {
    const assertion = getCloudflareAccessAssertion(request);
    const teamDomain = normalizeTeamDomain(env.TEAM_DOMAIN);
    const audience = env.POLICY_AUD?.trim();
    if (!assertion || !teamDomain || !audience) return null;

    const jwks = await getJwks(teamDomain, fetchAccess);
    const { payload } = await jwtVerify(assertion, jwks, {
      algorithms: ["RS256"],
      issuer: teamDomain,
      audience,
      clockTolerance: CLOCK_SKEW_SECONDS,
    });

    if (
      typeof payload.email !== "string" ||
      !payload.email.includes("@") ||
      payload.email.length > 320
    ) {
      return null;
    }

    return { email: payload.email.trim().toLowerCase() };
  } catch {
    return null;
  }
}
