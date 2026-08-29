import { createHash, createHmac, timingSafeEqual } from "node:crypto";

export function hashHex(algorithm: "md5" | "sha1" | "sha256", value: string) {
  return createHash(algorithm).update(value, "utf8").digest("hex");
}

export function hmacHex(algorithm: "sha1" | "sha256", secret: string, value: string) {
  return createHmac(algorithm, secret).update(value, "utf8").digest("hex");
}

export function safeEqual(left: string | null | undefined, right: string) {
  if (!left) return false;
  const a = Buffer.from(left.toLowerCase());
  const b = Buffer.from(right.toLowerCase());
  return a.length === b.length && timingSafeEqual(a, b);
}
