import { getD1 } from "@/db";

let securitySchema: Promise<void> | null = null;

function ensureSecuritySchema() {
  if (!securitySchema) {
    securitySchema = (async () => {
      const db = getD1();
      await db.batch([
        db.prepare("CREATE TABLE IF NOT EXISTS security_rate_limits (scope TEXT NOT NULL, bucket_start INTEGER NOT NULL, key_hash TEXT NOT NULL, hits INTEGER NOT NULL DEFAULT 0, PRIMARY KEY (scope, bucket_start, key_hash))"),
        db.prepare("CREATE TABLE IF NOT EXISTS admin_activity_logs (id TEXT PRIMARY KEY NOT NULL, admin_id INTEGER NOT NULL, admin_name TEXT NOT NULL, admin_role TEXT NOT NULL, action TEXT NOT NULL, target TEXT NOT NULL, created_at TEXT DEFAULT CURRENT_TIMESTAMP NOT NULL)"),
        db.prepare("CREATE INDEX IF NOT EXISTS admin_activity_logs_created_idx ON admin_activity_logs(created_at DESC)"),
      ]);
    })().catch((error) => {
      securitySchema = null;
      throw error;
    });
  }
  return securitySchema;
}

async function sha256(value: string) {
  const bytes = new TextEncoder().encode(value);
  const digest = await crypto.subtle.digest("SHA-256", bytes);
  return Array.from(new Uint8Array(digest), (byte) => byte.toString(16).padStart(2, "0")).join("");
}

function clientKey(request: Request) {
  const ip = request.headers.get("cf-connecting-ip") || "unknown";
  return sha256(ip);
}

export async function allowRequest(request: Request, scope: string, limit: number, windowSeconds = 600) {
  try {
    await ensureSecuritySchema();
    const bucket = Math.floor(Date.now() / (windowSeconds * 1000)) * windowSeconds;
    const keyHash = await clientKey(request);
    const row = await getD1().prepare(
      `INSERT INTO security_rate_limits (scope, bucket_start, key_hash, hits)
       VALUES (?, ?, ?, 1)
       ON CONFLICT(scope, bucket_start, key_hash) DO UPDATE SET hits = hits + 1
       RETURNING hits`,
    ).bind(scope, bucket, keyHash).first<{ hits: number }>();
    return { allowed: (row?.hits ?? 1) <= limit, retryAfter: windowSeconds - (Math.floor(Date.now() / 1000) - bucket) };
  } catch {
    // Every protected route also needs D1. Failing closed prevents an outage from disabling abuse protection.
    return { allowed: false, retryAfter: windowSeconds };
  }
}

export async function recordAdminActivity(input: { id: number; name: string; role: string }, action: string, target: string) {
  try {
    await ensureSecuritySchema();
    await getD1().prepare(
      "INSERT INTO admin_activity_logs (id, admin_id, admin_name, admin_role, action, target) VALUES (?, ?, ?, ?, ?, ?)",
    ).bind(crypto.randomUUID(), input.id, input.name.slice(0, 80), input.role, action, target.slice(0, 120)).run();
  } catch {
    // Activity logging must never block the operation the Owner or Staff requested.
  }
}


export function rejectCrossOriginMutation(request: Request) {
  if (["GET", "HEAD", "OPTIONS"].includes(request.method)) return null;

  const fetchSite = request.headers.get("sec-fetch-site")?.toLowerCase();
  if (fetchSite === "cross-site") {
    return Response.json({ error: "Permintaan lintas situs ditolak." }, { status: 403 });
  }

  const origin = request.headers.get("origin");
  if (origin) {
    try {
      if (new URL(origin).origin !== new URL(request.url).origin) {
        return Response.json({ error: "Permintaan lintas situs ditolak." }, { status: 403 });
      }
    } catch {
      return Response.json({ error: "Origin permintaan tidak valid." }, { status: 403 });
    }
  }

  return null;
}


export async function cleanupSecurityRateLimits(retentionSeconds = 172800) {
  try {
    await ensureSecuritySchema();
    const cutoff = Math.floor(Date.now() / 1000) - retentionSeconds;
    await getD1().prepare("DELETE FROM security_rate_limits WHERE bucket_start < ?").bind(cutoff).run();
  } catch {
    // Cleanup is best-effort and must never affect storefront traffic.
  }
}
