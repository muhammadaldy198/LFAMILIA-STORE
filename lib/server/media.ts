import { getRuntimeEnv } from "@/lib/server/runtime-env";

const MAX_MEDIA_BYTES = 6 * 1024 * 1024;
const MAX_D1_MEDIA_BYTES = 1_800_000;
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

type MediaBucket = {
  put(key: string, value: ArrayBuffer, options: { httpMetadata: { contentType: string; cacheControl: string }; customMetadata: { originalName: string } }): Promise<unknown>;
  get(key: string): Promise<StoredMedia | null>;
};

type MediaStatement = {
  bind(...values: Array<string | number | ArrayBuffer | null>): MediaStatement;
  run(): Promise<unknown>;
  first<T>(): Promise<T | null>;
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
  if (!/^media-[0-9a-f-]{36}\.(?:jpg|png|webp|gif)$/.test(key)) return null;
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
