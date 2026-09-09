export function isAllowedNavigationUrl(value: string) {
  const normalized = value.trim();
  if (!normalized) return false;
  if (normalized.startsWith("#")) return /^#[A-Za-z0-9_-]+$/.test(normalized);
  if (normalized.startsWith("/") && !normalized.startsWith("//")) return true;
  try {
    const url = new URL(normalized);
    return url.protocol === "https:" || url.protocol === "http:";
  } catch {
    return false;
  }
}

export function safeNavigationUrl(value: string | null | undefined, fallback = "") {
  const normalized = value?.trim() || "";
  return isAllowedNavigationUrl(normalized) ? normalized : fallback;
}
