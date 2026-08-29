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
