"use client";

type CachedSummary = {
  expiresAt: number;
  promise: Promise<unknown>;
};

const SUMMARY_CACHE_TTL_MS = 5_000;
const summaries = new Map<string, CachedSummary>();

export async function fetchAdminSummary<T>(
  range = "7d",
  options: { force?: boolean } = {},
): Promise<T> {
  const key = range || "7d";
  const now = Date.now();
  const cached = summaries.get(key);
  if (!options.force && cached && cached.expiresAt > now) {
    return cached.promise as Promise<T>;
  }

  const promise = fetch(`/api/panel/summary?range=${encodeURIComponent(key)}`, {
    cache: "no-store",
  }).then(async (response) => {
    const payload = await response.json().catch(() => ({})) as T & { error?: string };
    if (!response.ok) {
      throw new Error(payload.error || "Ringkasan Admin gagal dimuat.");
    }
    return payload;
  });

  summaries.set(key, {
    expiresAt: now + SUMMARY_CACHE_TTL_MS,
    promise,
  });

  promise.catch(() => {
    const current = summaries.get(key);
    if (current?.promise === promise) summaries.delete(key);
  });

  return promise;
}
