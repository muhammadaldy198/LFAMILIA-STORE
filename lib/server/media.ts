import { getRuntimeEnv } from "@/lib/server/runtime-env";

const MAX_MEDIA_BYTES = 6 * 1024 * 1024;
const mediaTypes: Record<string, string> = {
  "image/jpeg": "jpg",
  "image/png": "png",
  "image/webp": "webp",
  "image/gif": "gif",
};

type StoredMedia = {
  body: ReadableStream<Uint8Array>;
  httpEtag?: string;
  writeHttpMetadata(headers: Headers): void;
};

type MediaBucket = {
  put(key: string, value: ArrayBuffer, options: { httpMetadata: { contentType: string; cacheControl: string }; customMetadata: { originalName: string } }): Promise<unknown>;
  get(key: string): Promise<StoredMedia | null>;
};

function getMediaBucket() {
  const bucket = getRuntimeEnv<{ BUCKET?: MediaBucket }>().BUCKET;
  if (!bucket) throw new Error("Penyimpanan media belum aktif.");
  return bucket;
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
  await getMediaBucket().put(key, buffer, {
    httpMetadata: { contentType: file.type, cacheControl: "public, max-age=31536000, immutable" },
    customMetadata: { originalName: file.name.slice(0, 180) },
  });
  return key;
}

export async function readStoreMedia(key: string) {
  if (!/^media-[0-9a-f-]{36}\.(?:jpg|png|webp|gif)$/.test(key)) return null;
  return getMediaBucket().get(key);
}
