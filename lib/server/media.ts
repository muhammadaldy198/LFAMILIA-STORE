import { getRuntimeEnv } from "@/lib/server/runtime-env";

const MAX_MEDIA_BYTES = 6 * 1024 * 1024;
const MAX_D1_MEDIA_BYTES = 1_800_000;
const ORPHAN_GRACE_MS = 7 * 24 * 60 * 60 * 1000;
const MEDIA_KEY = /^media-[0-9a-f-]{36}\.(?:jpg|png|webp|gif)$/;
const mediaTypes: Record<string, string> = {
  "image/jpeg": "jpg",
  "image/png": "png",
  "image/webp": "webp",
  "image/gif": "gif",
};

type StoredMedia = {
  body: BodyInit;
  httpEtag?: string;
  writeHttpMetadata(headers: Headers): void;
};

type BucketObject = { key: string; uploaded?: Date | string };
type BucketList = { objects: BucketObject[]; truncated: boolean; cursor?: string };
type MediaBucket = {
  put(key: string, value: ArrayBuffer, options: { httpMetadata: { contentType: string; cacheControl: string }; customMetadata: { originalName: string } }): Promise<unknown>;
  get(key: string): Promise<StoredMedia | null>;
  list?(options?: { prefix?: string; cursor?: string; limit?: number }): Promise<BucketList>;
  delete?(keys: string | string[]): Promise<unknown>;
};

type MediaStatement = {
  bind(...values: Array<string | number | ArrayBuffer | null>): MediaStatement;
  run(): Promise<{ meta?: { changes?: number } }>;
  first<T>(): Promise<T | null>;
  all<T>(): Promise<{ results: T[] }>;
};

type MediaDatabase = {
  prepare(query: string): MediaStatement;
};

type MediaBindings = {
  BUCKET?: MediaBucket;
  DB?: MediaDatabase;
};

function getMediaBindings() {
  return getRuntimeEnv<MediaBindings>();
}

async function ensureMediaTable(database: MediaDatabase) {
  await database.prepare(`CREATE TABLE IF NOT EXISTS media_assets (
    media_key TEXT PRIMARY KEY,
    content_type TEXT NOT NULL,
    data BLOB NOT NULL,
    etag TEXT NOT NULL,
    original_name TEXT,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
  )`).run();
}

function hasExpectedSignature(bytes: Uint8Array, type: string) {
  if (type === "image/jpeg") return bytes[0] === 0xff && bytes[1] === 0xd8 && bytes[2] === 0xff;
  if (type === "image/png") return bytes[0] === 0x89 && bytes[1] === 0x50 && bytes[2] === 0x4e && bytes[3] === 0x47 && bytes[4] === 0x0d && bytes[5] === 0x0a && bytes[6] === 0x1a && bytes[7] === 0x0a;
  if (type === "image/gif") return String.fromCharCode(...bytes.slice(0, 6)) === "GIF87a" || String.fromCharCode(...bytes.slice(0, 6)) === "GIF89a";
  if (type === "image/webp") return String.fromCharCode(...bytes.slice(0, 4)) === "RIFF" && String.fromCharCode(...bytes.slice(8, 12)) === "WEBP";
  return false;
}

function mediaKeyFromValue(value: unknown) {
  if (typeof value !== "string") return null;
  const trimmed = value.trim();
  if (MEDIA_KEY.test(trimmed)) return trimmed;
  const match = trimmed.match(/\/api\/media\/(media-[0-9a-f-]{36}\.(?:jpg|png|webp|gif))(?:[?#].*)?$/i);
  return match?.[1] ?? null;
}

async function collectReferencedMediaKeys(database: MediaDatabase) {
  const referenced = new Set<string>();
  const queries = [
    "SELECT image_url AS a, banner_url AS b FROM products",
    "SELECT image_url AS a, NULL AS b FROM product_packages",
    "SELECT image_url AS a, NULL AS b FROM home_banners",
    "SELECT cover_url AS a, NULL AS b FROM news_articles",
    "SELECT logo_url AS a, banner_image_url AS b FROM store_settings",
    "SELECT proof_url AS a, NULL AS b FROM wallet_topups",
    "SELECT image_url AS a, NULL AS b FROM payment_channels",
  ];
  for (const query of queries) {
    try {
      const rows = await database.prepare(query).all<{ a: string | null; b: string | null }>();
      for (const row of rows.results) {
        const first = mediaKeyFromValue(row.a);
        const second = mediaKeyFromValue(row.b);
        if (first) referenced.add(first);
        if (second) referenced.add(second);
      }
    } catch {
      // Older databases can legitimately miss optional tables/columns.
    }
  }

  // Payment-page assets are stored inside config_json instead of dedicated
  // columns. Walk every string so cleanup cannot delete a logo/header that is
  // still referenced by the active payment-page configuration.
  try {
    const rows = await database.prepare(
      "SELECT config_json FROM payment_page_settings",
    ).all<{ config_json: string }>();
    const collect = (value: unknown) => {
      if (typeof value === "string") {
        const key = mediaKeyFromValue(value);
        if (key) referenced.add(key);
        return;
      }
      if (Array.isArray(value)) {
        for (const item of value) collect(item);
        return;
      }
      if (value && typeof value === "object") {
        for (const item of Object.values(value as Record<string, unknown>)) collect(item);
      }
    };
    for (const row of rows.results) {
      try {
        collect(JSON.parse(row.config_json));
      } catch {
        // Ignore malformed legacy JSON; valid references from other tables stay protected.
      }
    }
  } catch {
    // Older databases may not have payment_page_settings yet.
  }

  return referenced;
}

export async function uploadStoreMedia(file: File) {
  const extension = mediaTypes[file.type];
  if (!extension) throw new Error("Format gambar harus JPG, PNG, WEBP, atau GIF.");
  if (file.size < 1) throw new Error("File gambar kosong.");
  if (file.size > MAX_MEDIA_BYTES) throw new Error("Ukuran gambar maksimal 6 MB.");

  const buffer = await file.arrayBuffer();
  if (!hasExpectedSignature(new Uint8Array(buffer), file.type)) throw new Error("Isi file tidak cocok dengan format gambarnya.");

  const key = `media-${crypto.randomUUID()}.${extension}`;
  const { BUCKET: bucket, DB: database } = getMediaBindings();
  if (bucket) {
    await bucket.put(key, buffer, {
      httpMetadata: { contentType: file.type, cacheControl: "public, max-age=31536000, immutable" },
      customMetadata: { originalName: file.name.slice(0, 180) },
    });
  } else if (database) {
    if (buffer.byteLength > MAX_D1_MEDIA_BYTES) throw new Error("Gambar terlalu besar. Coba unggah ulang agar dikompres otomatis.");
    await ensureMediaTable(database);
    const digest = new Uint8Array(await crypto.subtle.digest("SHA-256", buffer));
    const etag = Array.from(digest, (byte) => byte.toString(16).padStart(2, "0")).join("");
    await database.prepare(`INSERT INTO media_assets
      (media_key, content_type, data, etag, original_name)
      VALUES (?, ?, ?, ?, ?)`)
      .bind(key, file.type, buffer, etag, file.name.slice(0, 180))
      .run();
  } else {
    throw new Error("Penyimpanan media belum aktif.");
  }
  return key;
}

export async function readStoreMedia(key: string) {
  if (!MEDIA_KEY.test(key)) return null;
  const { BUCKET: bucket, DB: database } = getMediaBindings();
  const bucketObject = bucket ? await bucket.get(key) : null;
  if (bucketObject) return bucketObject;
  if (!database) {
    if (!bucket) throw new Error("Penyimpanan media belum aktif.");
    return null;
  }

  await ensureMediaTable(database);
  const row = await database.prepare("SELECT content_type, data, etag FROM media_assets WHERE media_key = ?")
    .bind(key)
    .first<{ content_type: string; data: ArrayBuffer; etag: string }>();
  if (!row) return null;
  return {
    body: row.data,
    httpEtag: `"${row.etag}"`,
    writeHttpMetadata(headers: Headers) {
      headers.set("Content-Type", row.content_type);
    },
  } satisfies StoredMedia;
}

export async function cleanupOrphanStoreMedia(now = Date.now()) {
  const { BUCKET: bucket, DB: database } = getMediaBindings();
  if (!database) return { scanned: 0, deleted: 0 };
  const referenced = await collectReferencedMediaKeys(database);
  const cutoff = now - ORPHAN_GRACE_MS;
  let scanned = 0;
  let deleted = 0;

  if (bucket?.list && bucket.delete) {
    let cursor: string | undefined;
    for (let page = 0; page < 5; page += 1) {
      const result = await bucket.list({ prefix: "media-", cursor, limit: 100 });
      const stale: string[] = [];
      for (const object of result.objects) {
        scanned += 1;
        if (!MEDIA_KEY.test(object.key) || referenced.has(object.key)) continue;
        const uploadedAt = object.uploaded ? new Date(object.uploaded).getTime() : NaN;
        if (!Number.isFinite(uploadedAt) || uploadedAt > cutoff) continue;
        stale.push(object.key);
      }
      if (stale.length) {
        await bucket.delete(stale);
        deleted += stale.length;
      }
      if (!result.truncated || !result.cursor) break;
      cursor = result.cursor;
    }
  }

  try {
    await ensureMediaTable(database);
    const rows = await database.prepare("SELECT media_key, created_at FROM media_assets WHERE datetime(created_at) <= datetime('now', '-7 days') LIMIT 500")
      .all<{ media_key: string; created_at: string }>();
    for (const row of rows.results) {
      scanned += 1;
      if (!MEDIA_KEY.test(row.media_key) || referenced.has(row.media_key)) continue;
      const result = await database.prepare("DELETE FROM media_assets WHERE media_key = ? AND datetime(created_at) <= datetime('now', '-7 days')")
        .bind(row.media_key)
        .run();
      deleted += Number(result.meta?.changes ?? 0);
    }
  } catch {
    // R2 is primary storage; D1 fallback cleanup is best-effort.
  }

  return { scanned, deleted };
}
