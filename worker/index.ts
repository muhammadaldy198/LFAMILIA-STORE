import { logServerError } from "../lib/server/safe-log";
/** Cloudflare Worker entry point for the vinext-starter template. */
import { handleImageOptimization, DEFAULT_DEVICE_SIZES, DEFAULT_IMAGE_SIZES } from "vinext/server/image-optimization";
import handler from "vinext/server/app-router-entry";
import { getPublicBaseUrl, setRuntimeEnv } from "../lib/server/runtime-env";
import { hydrateIntegrationRuntimeEnv } from "../lib/server/integration-config";
import { hydrateDokuCheckoutRuntimeEnv } from "../lib/server/payment-mode-config";
import { expireUninitializedExternalOrders } from "../lib/server/external-payments";
import { ensureLegacyDatabaseColumns } from "../lib/server/database-repair";
import { recoverStaleAutomaticOrders } from "../lib/server/orders";
import { releaseExpiredExternalPromotions } from "../lib/server/promotions";
import { reconcileStaleDigiflazzProcessing } from "../lib/server/digiflazz-reconciliation";
import { finalizeExpiredDokuPayments } from "../lib/server/doku-reconciliation";
import {
  reconcilePendingMidtransOrders,
  reconcilePendingMidtransTopups,
} from "../lib/server/midtrans-reconciliation";
import { syncDigiflazzPrices } from "../lib/server/digiflazz-pricing";
import { cleanupSecurityRateLimits } from "../lib/server/security";
import { cleanupOrphanStoreMedia } from "../lib/server/media";
import { cleanupDormantCustomerAccounts } from "../lib/server/customer-cleanup";
import { expireUninitializedExternalWalletTopups } from "../lib/server/wallet-external";
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


function isVpsFrontendRuntime() {
  return process.env.VPS_FRONTEND_MODE === "1" || process.env.LFAMILIA_DEPLOY_TARGET === "node";
}

function laravelInternalOrigin() {
  return (
    process.env.LFAMILIA_LARAVEL_INTERNAL_URL ||
    process.env.LARAVEL_INTERNAL_URL ||
    "http://127.0.0.1:8080"
  ).replace(/\/+$/, "");
}

async function proxyApiToLaravel(request: Request, url: URL) {
  const target = new URL(`${url.pathname}${url.search}`, `${laravelInternalOrigin()}/`);
  const headers = new Headers(request.headers);
  headers.delete("content-length");
  headers.delete("host");
  headers.set("x-forwarded-host", url.host);
  headers.set("x-forwarded-proto", url.protocol.replace(":", ""));

  const method = request.method.toUpperCase();
  const body = method === "GET" || method === "HEAD"
    ? undefined
    : await request.arrayBuffer();

  return fetch(target, {
    method,
    headers,
    body,
    redirect: "manual",
  });
}

const RUNTIME_HYDRATION_TTL_MS = 15_000;
let runtimeHydrationCache: { expiresAt: number; promise: Promise<Env> } | null = null;
let requestRepairPrimed = false;

function isReadOnlyRequest(request: Request) {
  return request.method === "GET" || request.method === "HEAD" || request.method === "OPTIONS";
}

function requestNeedsHydratedRuntime(request: Request, url: URL) {
  if (!isReadOnlyRequest(request)) return true;

  const path = url.pathname;
  if (
    path.startsWith("/api/payments/") ||
    path.startsWith("/api/fulfillment/") ||
    path.startsWith("/api/auth/google") ||
    path === "/api/nickname" ||
    path === "/api/orders/status" ||
    path === "/api/payment-methods" ||
    path === "/api/wallet" ||
    path === "/api/system-status" ||
    path.startsWith("/api/admin/")
  ) {
    return true;
  }

  if (!path.startsWith("/api/panel/")) return false;
  const panelPath = path.slice("/api/panel/".length);
  return [
    "summary",
    "dashboard-integrations",
    "integrations",
    "payment-methods",
    "payment-routing",
    "wallet",
    "digiflazz-pricing",
    "digiflazz-monitor",
    "nickname-tools",
  ].some((prefix) => panelPath === prefix || panelPath.startsWith(`${prefix}/`));
}

async function hydrateRuntime(env: Env) {
  const now = Date.now();
  if (!runtimeHydrationCache || runtimeHydrationCache.expiresAt <= now) {
    const promise = (async () => {
      const integrated = await hydrateIntegrationRuntimeEnv(env);
      const withDoku = await hydrateDokuCheckoutRuntimeEnv(integrated);
      return withDoku as Env;
    })();
    runtimeHydrationCache = {
      expiresAt: now + RUNTIME_HYDRATION_TTL_MS,
      promise,
    };
    promise.catch(() => {
      if (runtimeHydrationCache?.promise === promise) runtimeHydrationCache = null;
    });
  }

  const hydrated = await runtimeHydrationCache.promise;
  setRuntimeEnv(hydrated);
  return hydrated;
}

const worker = {
  async fetch(request: Request, env: Env | undefined, ctx: ExecutionContext | undefined): Promise<Response> {
    const runtimeEnv = env ?? ({} as Env);
    const runtimeCtx: ExecutionContext = ctx ?? {
      waitUntil(promise) {
        void promise.catch(() => undefined);
      },
      passThroughOnException() {},
    };
    const url = new URL(request.url);

    // Production VPS has one database authority: Laravel + MariaDB.
    // Nginx already routes public /api/* to Laravel; this guard also protects
    // direct Node/Vinext access so the legacy D1 handlers cannot run on VPS.
    if (
      isVpsFrontendRuntime() &&
      (url.pathname === "/api" || url.pathname.startsWith("/api/"))
    ) {
      return withSecurityHeaders(await proxyApiToLaravel(request, url), url);
    }
    const isAccessProtectedRequest =
      Boolean(runtimeEnv.DB) && (
        url.pathname === "/admin/panel" ||
        url.pathname.startsWith("/admin/panel/") ||
        url.pathname === "/admin/setup" ||
        url.pathname.startsWith("/admin/setup/") ||
        url.pathname === "/api/admin" ||
        url.pathname.startsWith("/api/admin/")
      );

    if (isAccessProtectedRequest) {
      const accessIdentity = await verifyCloudflareAccess(request, runtimeEnv);
      const adminEmail = accessIdentity?.email ?? null;

      if (!adminEmail) {
        const accessConfig = getCloudflareAccessConfigStatus(runtimeEnv);
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

        const diagnostic = diagnoseCloudflareAccessRequest(request, runtimeEnv);
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

    // Cloudflare injects D1/provider bindings. The standalone Node/VPS
    // frontend intentionally has no D1 binding because /api/* is served by
    // Laravel/MariaDB. Keep page rendering alive without touching legacy D1.
    if (runtimeEnv.DB) {
      if (requestNeedsHydratedRuntime(request, url)) {
        await hydrateRuntime(runtimeEnv);
      } else {
        // Never overwrite an already-hydrated isolate with the raw environment:
        // a concurrent payment/provider request may be reading that snapshot.
        if (!runtimeHydrationCache) setRuntimeEnv(runtimeEnv);
        runtimeCtx.waitUntil(
          hydrateRuntime(runtimeEnv).catch((error) => {
            logServerError("Pemanasan konfigurasi runtime gagal:", error);
          }),
        );
      }
    } else {
      setRuntimeEnv(runtimeEnv);
    }

    if (runtimeEnv.DB) {
      if (isReadOnlyRequest(request)) {
        if (!requestRepairPrimed) {
          requestRepairPrimed = true;
          runtimeCtx.waitUntil(
            ensureLegacyDatabaseColumns().catch((error) => {
              requestRepairPrimed = false;
              logServerError("Perbaikan kompatibilitas D1 background gagal:", error);
            }),
          );
        }
      } else {
        await ensureLegacyDatabaseColumns().catch((error) => {
          logServerError("Perbaikan kompatibilitas D1 gagal; request tetap diteruskan:", error);
        });
      }
    }

    if (url.pathname === "/_vinext/image") {
      if (!runtimeEnv.IMAGES) return withSecurityHeaders(new Response("Image optimization is unavailable.", { status: 404 }), url);
      const images = runtimeEnv.IMAGES;
      const allowedWidths = [...DEFAULT_DEVICE_SIZES, ...DEFAULT_IMAGE_SIZES];
      return withSecurityHeaders(await handleImageOptimization(request, {
        fetchAsset: (path) => runtimeEnv.ASSETS.fetch(new Request(new URL(path, request.url))),
        transformImage: async (body, { width, format, quality }) => {
          const result = await images.input(body).transform(width > 0 ? { width } : {}).output({ format, quality });
          return result.response();
        },
      }, allowedWidths), url);
    }

    let response = await handler.fetch(request, runtimeEnv, runtimeCtx);

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
    await hydrateRuntime(env);
    await ensureLegacyDatabaseColumns().catch((error) => {
      logServerError("Perbaikan kompatibilitas D1 pada scheduler gagal:", error);
    });
    const paymentRecovery = Promise.all([
      finalizeExpiredDokuPayments().catch((error) => {
        logServerError("Rekonsiliasi DOKU scheduler gagal:", error);
      }),
      reconcilePendingMidtransOrders().catch((error) => {
        logServerError("Rekonsiliasi order Midtrans scheduler gagal:", error);
      }),
      reconcilePendingMidtransTopups().catch((error) => {
        logServerError("Rekonsiliasi top up Midtrans scheduler gagal:", error);
      }),
    ]).then(async () => {
      // Only expire ambiguous/uninitialized attempts after every available
      // provider reconciliation path has had a chance to settle them.
      await Promise.all([
        expireUninitializedExternalWalletTopups().catch((error) => {
          logServerError("Expiry top up eksternal belum terinisialisasi gagal:", error);
        }),
        expireUninitializedExternalOrders().catch((error) => {
          logServerError("Expiry order eksternal belum terinisialisasi gagal:", error);
        }),
      ]);
      await releaseExpiredExternalPromotions().catch((error) => {
        logServerError("Pelepasan reservasi promo kedaluwarsa gagal:", error);
      });
    });

    const tasks: Promise<unknown>[] = [
      cleanupSecurityRateLimits().catch(() => undefined),
      paymentRecovery,
      Promise.resolve()
        .then(() => getPublicBaseUrl())
        .then((publicBaseUrl) => recoverStaleAutomaticOrders(publicBaseUrl))
        .catch((error) => logServerError("Recovery order otomatis gagal:", error)),
      Promise.resolve()
        .then(() => getPublicBaseUrl())
        .then((publicBaseUrl) => reconcileStaleDigiflazzProcessing(publicBaseUrl))
        .catch((error) => logServerError("Rekonsiliasi DigiFlazz scheduler gagal:", error)),
    ];
    if (event.cron === "5 * * * *") {
      tasks.push(syncDigiflazzPrices().catch(() => undefined));
    }
    if (event.cron === "15 2 * * *") {
      tasks.push(cleanupOrphanStoreMedia().catch(() => undefined));
      tasks.push(
        cleanupDormantCustomerAccounts().catch((error) => {
          logServerError("Pembersihan akun pelanggan kosong gagal:", error);
        }),
      );
    }
    ctx.waitUntil(Promise.all(tasks));
  },
};

export default worker;
