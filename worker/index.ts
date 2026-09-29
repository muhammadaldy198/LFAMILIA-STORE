/** Vinext frontend runtime.
 *
 * Production data is owned exclusively by Laravel + MariaDB on the VPS.
 * The retired Cloudflare Worker/D1 backend must never become a second runtime
 * data source. In VPS mode every /api/* request is forwarded to Laravel.
 */
import { handleImageOptimization, DEFAULT_DEVICE_SIZES, DEFAULT_IMAGE_SIZES } from "vinext/server/image-optimization";
import handler from "vinext/server/app-router-entry";

interface Env {
  ASSETS?: Fetcher;
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

function retiredApiResponse() {
  return Response.json(
    {
      error: "API LFAMILIA dijalankan oleh Laravel/MariaDB pada VPS.",
      code: "RETIRED_WORKER_API",
    },
    {
      status: 503,
      headers: { "Cache-Control": "no-store" },
    },
  );
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

    // Single-database invariant:
    // browser -> /api/* -> Laravel -> MariaDB.
    // Even requests sent directly to the Node/Vinext port cannot execute the
    // retired D1 API implementation.
    if (url.pathname === "/api" || url.pathname.startsWith("/api/")) {
      const response = isVpsFrontendRuntime()
        ? await proxyApiToLaravel(request, url)
        : retiredApiResponse();
      return withSecurityHeaders(response, url);
    }

    if (url.pathname === "/_vinext/image") {
      if (!runtimeEnv.IMAGES || !runtimeEnv.ASSETS) {
        return withSecurityHeaders(
          new Response("Image optimization is unavailable.", { status: 404 }),
          url,
        );
      }
      const images = runtimeEnv.IMAGES;
      const assets = runtimeEnv.ASSETS;
      const allowedWidths = [...DEFAULT_DEVICE_SIZES, ...DEFAULT_IMAGE_SIZES];
      return withSecurityHeaders(
        await handleImageOptimization(
          request,
          {
            fetchAsset: (path) => assets.fetch(new Request(new URL(path, request.url))),
            transformImage: async (body, { width, format, quality }) => {
              const result = await images
                .input(body)
                .transform(width > 0 ? { width } : {})
                .output({ format, quality });
              return result.response();
            },
          },
          allowedWidths,
        ),
        url,
      );
    }

    let response = await handler.fetch(request, runtimeEnv, runtimeCtx);

    const isPanelPage =
      url.pathname === "/panel" ||
      url.pathname.startsWith("/panel/") ||
      url.pathname === "/staff" ||
      url.pathname.startsWith("/staff/") ||
      url.pathname === "/admin" ||
      url.pathname.startsWith("/admin/");

    if (request.method === "GET" && isPanelPage) {
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
};

export default worker;
