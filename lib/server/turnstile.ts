import { getRuntimeEnv } from "@/lib/server/runtime-env";

type TurnstileEnv = {
  TURNSTILE_SITE_KEY?: string;
  TURNSTILE_SECRET_KEY?: string;
};

type SiteverifyResponse = {
  success?: boolean;
  "error-codes"?: string[];
};

function config() {
  const env = getRuntimeEnv<TurnstileEnv>();
  const siteKey = env.TURNSTILE_SITE_KEY?.trim() || "";
  const secretKey = env.TURNSTILE_SECRET_KEY?.trim() || "";
  if (Boolean(siteKey) !== Boolean(secretKey)) {
    throw new Error("Konfigurasi Turnstile belum lengkap. Isi Site Key dan Secret Key bersamaan.");
  }
  return { enabled: Boolean(siteKey && secretKey), siteKey, secretKey };
}

export function getTurnstilePublicConfig() {
  const current = config();
  return { enabled: current.enabled, siteKey: current.enabled ? current.siteKey : null };
}

export async function verifyTurnstile(request: Request, token: string | undefined | null) {
  const current = config();
  if (!current.enabled) return true;
  const responseToken = token?.trim();
  if (!responseToken) return false;

  const form = new FormData();
  form.set("secret", current.secretKey);
  form.set("response", responseToken);
  const ip = request.headers.get("cf-connecting-ip")?.trim();
  if (ip) form.set("remoteip", ip);

  const response = await fetch("https://challenges.cloudflare.com/turnstile/v0/siteverify", {
    method: "POST",
    body: form,
    signal: AbortSignal.timeout(8_000),
  });
  if (!response.ok) throw new Error("Verifikasi Turnstile sedang tidak tersedia.");

  const data = await response.json() as SiteverifyResponse;
  return data.success === true;
}
