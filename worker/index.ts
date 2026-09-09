/** Cloudflare Worker entry point for the vinext-starter template. */
import { handleImageOptimization, DEFAULT_DEVICE_SIZES, DEFAULT_IMAGE_SIZES } from "vinext/server/image-optimization";
import handler from "vinext/server/app-router-entry";
import { getPublicBaseUrl, setRuntimeEnv } from "../lib/server/runtime-env";
import { hydrateIntegrationRuntimeEnv } from "../lib/server/integration-config";
import { recoverStaleAutomaticOrders } from "../lib/server/orders";
import { syncDigiflazzPrices } from "../lib/server/digiflazz-pricing";
import { cleanupSecurityRateLimits } from "../lib/server/security";
import {
  diagnoseCloudflareAccessRequest,
  getCloudflareAccessAssertion,
  getCloudflareAccessConfigStatus,
  verifyCloudflareAccess,
} from "../lib/server/cloudflare-access";

interface Env {
  ASSETS: Fetcher;
  DB: D1Database;
  BUCKET?: object;
  TEAM_DOMAIN?: string;
  POLICY_AUD?: string;
  IMAGES?: {
    input(stream: ReadableStream): {
      transform(options: Record<string, unknown>): {
        output(options: { format: string; quality: number }): Promise<{ response(): Response }>;
      };
    };
  };
}

interface ExecutionContext {
  waitUntil(promise: Promise<unknown>): void;
  passThroughOnException(): void;
}

interface ScheduledEvent { cron: string; }

function escapeHtml(value: string | undefined | null) {
  return (value ?? "")
    .replaceAll("&", "&amp;")
    .replaceAll("<", "&lt;")
    .replaceAll(">", "&gt;")
    .replaceAll('"', "&quot;")
    .replaceAll("'", "&#39;");
}

function withSecurityHeaders(response: Response, url: URL) {
  const headers = new Headers(response.headers);
  headers.set("X-Content-Type-Options", "nosniff");
  headers.set("X-Frame-Options", "DENY");
  headers.set("Referrer-Policy", "strict-origin-when-cross-origin");
  headers.set("Permissions-Policy", "camera=(), microphone=(), geolocation=()");
  headers.set("X-Permitted-Cross-Domain-Policies", "none");
  headers.set("Content-Security-Policy", "frame-ancestors 'none'; base-uri 'self'; object-src 'none'");
  if (url.protocol === "https:") {
    headers.set("Strict-Transport-Security", "max-age=31536000; includeSubDomains");
  }
  return new Response(response.body, {
    status: response.status,
    statusText: response.statusText,
    headers,
  });
}

// Image security config. SVG sources with .svg extension auto-skip the
// optimization endpoint on the client side (served directly, no proxy).
// To route SVGs through the optimizer (with security headers), set
// dangerouslyAllowSVG: true in next.config.js and uncomment below:
// const imageConfig: ImageConfig = { dangerouslyAllowSVG: true };

const worker = {
  async fetch(request: Request, env: Env, ctx: ExecutionContext): Promise<Response> {
    const url = new URL(request.url);
    const isAccessProtectedRequest =
      url.pathname === "/admin/panel" ||
      url.pathname.startsWith("/admin/panel/") ||
      url.pathname === "/admin/setup" ||
      url.pathname.startsWith("/admin/setup/") ||
      url.pathname === "/api/admin" ||
      url.pathname.startsWith("/api/admin/");

    if (isAccessProtectedRequest) {
      const accessIdentity = await verifyCloudflareAccess(request, env);
      const adminEmail = accessIdentity?.email ?? null;

      if (!adminEmail) {
        const accessConfig = getCloudflareAccessConfigStatus(env);
        const hasAssertion = Boolean(getCloudflareAccessAssertion(request));
        const reason = !accessConfig.teamDomainConfigured
          ? "TEAM_DOMAIN_INVALID"
          : !accessConfig.audienceConfigured
            ? "POLICY_AUD_MISSING"
            : !hasAssertion
              ? "ACCESS_TOKEN_MISSING"
              : "ACCESS_TOKEN_INVALID";

        if (url.pathname.startsWith("/api/")) {
          return withSecurityHeaders(
            Response.json(
              {
                error: "Cloudflare Access belum memvalidasi area Admin.",
                reason,
              },
              { status: 401 },
            ),
            url,
          );
        }

        const diagnostic = diagnoseCloudflareAccessRequest(request, env);
        const detailedReason = diagnostic.reason;
        const explanation =
          detailedReason === "TEAM_DOMAIN_INVALID"
            ? "TEAM_DOMAIN kosong atau formatnya tidak valid."
            : detailedReason === "POLICY_AUD_MISSING"
              ? "POLICY_AUD belum tersedia di Worker."
              : detailedReason === "ACCESS_TOKEN_MISSING"
                ? "Cloudflare Access tidak mengirim token ke Worker. Periksa Application path/policy Access."
                : detailedReason === "ACCESS_AUDIENCE_MISMATCH"
                  ? "Application Audience (AUD) pada token tidak sama dengan POLICY_AUD Worker."
                  : detailedReason === "ACCESS_ISSUER_MISMATCH"
                    ? "Issuer token tidak sama dengan TEAM_DOMAIN Worker."
                    : detailedReason === "ACCESS_TOKEN_EXPIRED"
                      ? "Token Cloudflare Access sudah kedaluwarsa. Login ulang ke Access."
                      : detailedReason === "ACCESS_TOKEN_TIME_INVALID"
                        ? "Waktu token Cloudflare Access tidak valid."
                        : detailedReason === "ACCESS_EMAIL_MISSING"
                          ? "Token Access tidak memiliki email pengguna yang valid."
                          : detailedReason === "ACCESS_TOKEN_MALFORMED"
                            ? "Token Access yang diterima tidak berbentuk JWT yang valid."
                            : "AUD, issuer, masa berlaku, dan email terlihat benar; signature/JWKS token yang gagal diverifikasi.";

        const expectedAudience =
          "expectedAudience" in diagnostic ? diagnostic.expectedAudience : "";
        const receivedAudience =
          "receivedAudience" in diagnostic ? diagnostic.receivedAudience : "";
        const expectedIssuer =
          "expectedIssuer" in diagnostic ? diagnostic.expectedIssuer : "";
        const receivedIssuer =
          "receivedIssuer" in diagnostic ? diagnostic.receivedIssuer : "";

        const details = expectedAudience
          ? `<p style="font-size:11px;color:#ffffff66;word-break:break-all">AUD Worker: ${escapeHtml(expectedAudience)}<br>AUD Token: ${escapeHtml(receivedAudience)}</p>`
          : expectedIssuer
            ? `<p style="font-size:11px;color:#ffffff66;word-break:break-all">TEAM_DOMAIN: ${escapeHtml(expectedIssuer)}<br>Issuer Token: ${escapeHtml(receivedIssuer)}</p>`
            : "";

        return withSecurityHeaders(new Response(
          `<!doctype html><html lang="id"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Admin belum dilindungi</title><body style="margin:0;background:#07090f;color:#fff;font-family:system-ui;display:grid;min-height:100vh;place-items:center"><main style="max-width:620px;padding:32px;text-align:center"><h1 style="color:#b9ff35">Admin belum dilindungi</h1><p style="color:#ffffff99;line-height:1.7">${explanation}</p><p style="font-size:12px;color:#ffffff66">Kode: ${detailedReason}</p>${details}<a href="/" style="color:#b9ff35">Kembali ke toko</a></main></body></html>`,
          { status: 401, headers: { "content-type": "text/html; charset=utf-8" } },
        ), url);
      }

      const headers = new Headers(request.headers);
      headers.delete("x-lfamilia-admin-email");
      headers.set("x-lfamilia-admin-email", adminEmail);
      request = new Request(request, { headers });
    }

    setRuntimeEnv(await hydrateIntegrationRuntimeEnv(env));

    if (url.pathname === "/_vinext/image") {
      if (!env.IMAGES) return withSecurityHeaders(new Response("Image optimization is unavailable.", { status: 404 }), url);
      const images = env.IMAGES;
      const allowedWidths = [...DEFAULT_DEVICE_SIZES, ...DEFAULT_IMAGE_SIZES];
      return withSecurityHeaders(await handleImageOptimization(request, {
        fetchAsset: (path) => env.ASSETS.fetch(new Request(new URL(path, request.url))),
        transformImage: async (body, { width, format, quality }) => {
          const result = await images.input(body).transform(width > 0 ? { width } : {}).output({ format, quality });
          return result.response();
        },
      }, allowedWidths), url);
    }

    let response = await handler.fetch(request, env, ctx);

    const isPanelPage =
      url.pathname === "/panel" ||
      url.pathname.startsWith("/panel/") ||
      url.pathname === "/staff" ||
      url.pathname.startsWith("/staff/") ||
      url.pathname === "/admin" ||
      url.pathname.startsWith("/admin/");

    const isSensitiveAdminApi =
      url.pathname === "/api/admin" || url.pathname.startsWith("/api/admin/");
    if ((request.method === "GET" && isPanelPage) || isSensitiveAdminApi) {
      const headers = new Headers(response.headers);
      headers.set("Cache-Control", "no-store, no-cache, must-revalidate");
      headers.set("CDN-Cache-Control", "no-store");
      headers.set("Cloudflare-CDN-Cache-Control", "no-store");
      response = new Response(response.body, {
        status: response.status,
        statusText: response.statusText,
        headers,
      });
    }

    return withSecurityHeaders(response, url);
  },
  async scheduled(event: ScheduledEvent, env: Env, ctx: ExecutionContext) {
    setRuntimeEnv(await hydrateIntegrationRuntimeEnv(env));
    const tasks: Promise<unknown>[] = [
      cleanupSecurityRateLimits().catch(() => undefined),
      Promise.resolve()
        .then(() => recoverStaleAutomaticOrders(getPublicBaseUrl()))
        .catch(() => undefined),
    ];
    if (event.cron === "15 2 * * *") {
      tasks.push(syncDigiflazzPrices().catch(() => undefined));
    }
    ctx.waitUntil(Promise.all(tasks));
  },
};

export default worker;
