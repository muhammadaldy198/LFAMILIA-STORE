import {
  createHash,
  createHmac,
  createPrivateKey,
  createPublicKey,
  sign as cryptoSign,
  timingSafeEqual,
  verify as cryptoVerify,
} from "node:crypto";

export function hashHex(
  algorithm: "md5" | "sha1" | "sha256" | "sha512",
  value: string,
) {
  return createHash(algorithm).update(value, "utf8").digest("hex");
}

export function hmacHex(
  algorithm: "sha1" | "sha256" | "sha512",
  secret: string,
  value: string,
) {
  return createHmac(algorithm, secret).update(value, "utf8").digest("hex");
}

export function hmacBase64(
  algorithm: "sha256" | "sha512",
  secret: string,
  value: string,
) {
  return createHmac(algorithm, secret).update(value, "utf8").digest("base64");
}

export function signRsaSha256Base64(privateKeyPem: string, value: string) {
  const key = createPrivateKey(privateKeyPem);
  return cryptoSign("RSA-SHA256", Buffer.from(value, "utf8"), key).toString(
    "base64",
  );
}

export function verifyRsaSha256Base64(
  publicKeyPem: string,
  value: string,
  signatureBase64: string,
) {
  try {
    const key = createPublicKey(publicKeyPem);
    return cryptoVerify(
      "RSA-SHA256",
      Buffer.from(value, "utf8"),
      key,
      Buffer.from(signatureBase64, "base64"),
    );
  } catch {
    return false;
  }
}

export function safeEqual(left: string | null | undefined, right: string) {
  if (!left) return false;
  const a = Buffer.from(left.toLowerCase());
  const b = Buffer.from(right.toLowerCase());
  return a.length === b.length && timingSafeEqual(a, b);
}
