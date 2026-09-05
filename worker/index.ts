/** Cloudflare Worker entry point for the vinext-starter template. */
import { handleImageOptimization, DEFAULT_DEVICE_SIZES, DEFAULT_IMAGE_SIZES } from "vinext/server/image-optimization";
import handler from "vinext/server/app-router-entry";
import { setRuntimeEnv } from "../lib/server/runtime-env";
import { syncDigiflazzPrices } from "../lib/server/digiflazz-pricing";

interface Env {
  ASSETS: Fetcher;
  DB: D1Database;
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
  access?: {
    getIdentity(): Promise<{ email?: string | null }>;
  };
}

interface ScheduledEvent { cron: string; }

// Image security config. SVG sources with .svg extension auto-skip the
// optimization endpoint on the client side (served directly, no proxy).
// To route SVGs through the optimizer (with security headers), set
// dangerouslyAllowSVG: true in next.config.js and uncomment below:
// const imageConfig: ImageConfig = { dangerouslyAllowSVG: true };

const worker = {
  async fetch(request: Request, env: Env, ctx: ExecutionContext): Promise<Response> {
    setRuntimeEnv(env);
    const url = new URL(request.url);
    const isAccessProtectedRequest =
      url.pathname === "/admin/panel" ||
      url.pathname.startsWith("/admin/panel/") ||
      url.pathname === "/admin/setup" ||
      url.pathname.startsWith("/admin/setup/") ||
      url.pathname === "/api/admin/auth/setup";

    if (isAccessProtectedRequest) {
      let adminEmail: string | null = null;

      if (ctx.access) {
        const identity = await ctx.access.getIdentity();
        adminEmail = identity?.email ?? null;
      } else {
        const accessEmail = request.headers.get("cf-access-authenticated-user-email");
        const accessAssertion = request.headers.get("cf-access-jwt-assertion");
        if (accessEmail && accessAssertion) adminEmail = accessEmail;
      }

      if (!adminEmail) {
        if (url.pathname.startsWith("/api/")) {
          return Response.json({ error: "Cloudflare Access belum melindungi setup Pemilik." }, { status: 401 });
        }

        return new Response(
          "<!doctype html><html lang=\"id\"><meta name=\"viewport\" content=\"width=device-width,initial-scale=1\"><title>Admin belum dilindungi</title><body style=\"margin:0;background:#07090f;color:#fff;font-family:system-ui;display:grid;min-height:100vh;place-items:center\"><main style=\"max-width:520px;padding:32px;text-align:center\"><h1 style=\"color:#b9ff35\">Admin belum dilindungi</h1><p style=\"color:#ffffff99;line-height:1.7\">Aktifkan Cloudflare Access untuk /admin/panel* dan /admin/setup* sebelum membuka area Admin.</p><a href=\"/\" style=\"color:#b9ff35\">Kembali ke toko</a></main></body></html>",
          { status: 401, headers: { "content-type": "text/html; charset=utf-8" } },
        );
      }

      const headers = new Headers(request.headers);
      headers.delete("x-lfamilia-admin-email");
      headers.set("x-lfamilia-admin-email", adminEmail);
      request = new Request(request, { headers });
    }

    if (url.pathname === "/_vinext/image") {
      if (!env.IMAGES) return new Response("Image optimization is unavailable.", { status: 404 });
      const images = env.IMAGES;
      const allowedWidths = [...DEFAULT_DEVICE_SIZES, ...DEFAULT_IMAGE_SIZES];
      return handleImageOptimization(request, {
        fetchAsset: (path) => env.ASSETS.fetch(new Request(new URL(path, request.url))),
        transformImage: async (body, { width, format, quality }) => {
          const result = await images.input(body).transform(width > 0 ? { width } : {}).output({ format, quality });
          return result.response();
        },
      }, allowedWidths);
    }

    return handler.fetch(request, env, ctx);
  },
  async scheduled(_event: ScheduledEvent, env: Env, ctx: ExecutionContext) {
    setRuntimeEnv(env);
    ctx.waitUntil(syncDigiflazzPrices().catch(() => undefined));
  },
};

export default worker;
