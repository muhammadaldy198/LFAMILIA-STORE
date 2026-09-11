import { NextRequest, NextResponse } from "next/server";

const safeMethods = new Set(["GET", "HEAD", "OPTIONS"]);

/**
 * API panel normally goes through /api/panel, which already rejects cross-site
 * mutations. The concrete /api/admin routes are also public HTTP paths, so the
 * same boundary must apply when a route is reached directly.
 */
export function proxy(request: NextRequest) {
  if (safeMethods.has(request.method)) return NextResponse.next();

  if (request.headers.get("sec-fetch-site")?.toLowerCase() === "cross-site") {
    return NextResponse.json({ error: "Permintaan lintas situs ditolak." }, { status: 403 });
  }

  const origin = request.headers.get("origin");
  if (!origin) return NextResponse.next();

  try {
    if (new URL(origin).origin !== request.nextUrl.origin) {
      return NextResponse.json({ error: "Permintaan lintas situs ditolak." }, { status: 403 });
    }
  } catch {
    return NextResponse.json({ error: "Origin permintaan tidak valid." }, { status: 403 });
  }

  return NextResponse.next();
}

export const config = {
  matcher: ["/api/admin/:path*"],
};
