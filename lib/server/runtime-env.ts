type RuntimeGlobal = typeof globalThis & {
  __LFAMILIA_RUNTIME_ENV__?: object;
};

export function setRuntimeEnv(value: object) {
  (globalThis as RuntimeGlobal).__LFAMILIA_RUNTIME_ENV__ = value;
}

export function getRuntimeEnv<T extends object>() {
  const value = (globalThis as RuntimeGlobal).__LFAMILIA_RUNTIME_ENV__;
  if (!value) throw new Error("Runtime Cloudflare belum tersedia.");
  return value as T;
}

export function requireRuntimeValue(value: string | null | undefined, name: string) {
  const normalized = value?.trim();
  if (!normalized) throw new Error(`${name} belum dikonfigurasi di Cloudflare.`);
  return normalized;
}

export function requireRuntimeChoice<T extends string>(
  value: string | null | undefined,
  name: string,
  allowed: readonly T[],
): T {
  const normalized = requireRuntimeValue(value, name).toLowerCase();
  if (!allowed.includes(normalized as T)) {
    throw new Error(`${name} harus salah satu dari: ${allowed.join(", ")}.`);
  }
  return normalized as T;
}
