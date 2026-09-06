const localMediaPath = /^\/(?:api\/media\/media-[0-9a-f-]{36}\.(?:jpg|png|webp|gif)|(?:brand|products)\/[a-z0-9][a-z0-9._/-]*\.(?:jpg|jpeg|png|webp|gif))$/i;

export function isAllowedMediaUrl(value: string) {
  if (!value) return true;
  if (localMediaPath.test(value)) return true;

  try {
    const url = new URL(value);
    return url.protocol === "https:" && !url.username && !url.password;
  } catch {
    return false;
  }
}
