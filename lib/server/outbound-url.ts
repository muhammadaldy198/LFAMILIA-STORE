const LOCAL_HOST_SUFFIXES = [".localhost", ".local", ".internal", ".home.arpa"];

function normalizedHostname(value: string) {
  return value.trim().toLowerCase().replace(/^\[/, "").replace(/\]$/, "").replace(/\.$/, "");
}

function ipv4Octets(hostname: string) {
  const parts = hostname.split(".");
  if (parts.length !== 4) return null;
  const values = parts.map((part) => /^\d{1,3}$/.test(part) ? Number(part) : NaN);
  if (values.some((value) => !Number.isInteger(value) || value < 0 || value > 255)) return null;
  return values as [number, number, number, number];
}

function isBlockedIpv4(hostname: string) {
  const octets = ipv4Octets(hostname);
  if (!octets) return false;
  const [a, b] = octets;
  return (
    a === 0 ||
    a === 10 ||
    a === 127 ||
    (a === 100 && b >= 64 && b <= 127) ||
    (a === 169 && b === 254) ||
    (a === 172 && b >= 16 && b <= 31) ||
    (a === 192 && b === 0) ||
    (a === 192 && b === 168) ||
    (a === 198 && (b === 18 || b === 19)) ||
    a >= 224
  );
}

function isBlockedIpv6(hostname: string) {
  if (!hostname.includes(":")) return false;
  const normalized = hostname.toLowerCase();
  if (normalized === "::" || normalized === "::1") return true;
  if (normalized.startsWith("fc") || normalized.startsWith("fd")) return true;
  if (/^fe[89ab]/.test(normalized)) return true;

  const mapped = normalized.match(/(?:^|:)ffff:(\d{1,3}(?:\.\d{1,3}){3})$/);
  return mapped ? isBlockedIpv4(mapped[1]) : false;
}

export function assertSafeHttpsUrl(value: string, label = "URL") {
  let parsed: URL;
  try {
    parsed = new URL(value.trim());
  } catch {
    throw new Error(`${label} tidak valid.`);
  }

  if (parsed.protocol !== "https:") {
    throw new Error(`${label} wajib menggunakan HTTPS.`);
  }
  if (parsed.username || parsed.password) {
    throw new Error(`${label} tidak boleh memuat credential.`);
  }

  const hostname = normalizedHostname(parsed.hostname);
  const localName =
    hostname === "localhost" ||
    LOCAL_HOST_SUFFIXES.some((suffix) => hostname.endsWith(suffix));

  if (!hostname || localName || isBlockedIpv4(hostname) || isBlockedIpv6(hostname)) {
    throw new Error(`${label} harus menunjuk host publik.`);
  }

  return parsed;
}

export function safeHttpsOrigin(value: string, label = "URL") {
  const parsed = assertSafeHttpsUrl(value, label);
  parsed.pathname = "/";
  parsed.search = "";
  parsed.hash = "";
  return parsed.origin;
}
