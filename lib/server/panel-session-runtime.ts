export const PANEL_COOKIE_NAME = "lfamilia_panel_session";

export type RuntimePanelSession = {
  id: number;
  email: string;
  name: string;
  role: "super_admin" | "admin" | "staff";
};

export async function getRuntimePanelSession(
  token: string | null | undefined,
): Promise<RuntimePanelSession | null> {
  if (!token) return null;

  if (process.env.LFAMILIA_DEPLOY_TARGET === "node") {
    const base = (process.env.LARAVEL_INTERNAL_URL || "http://127.0.0.1:8080").replace(/\/$/, "");
    try {
      const response = await fetch(`${base}/api/admin/session`, {
        headers: {
          cookie: `${PANEL_COOKIE_NAME}=${encodeURIComponent(token)}`,
          accept: "application/json",
        },
        cache: "no-store",
      });
      if (!response.ok) return null;
      const payload = await response.json() as { session?: RuntimePanelSession };
      return payload.session ?? null;
    } catch {
      return null;
    }
  }

  const { getPanelSessionFromToken } = await import("@/lib/server/admin-auth");
  return getPanelSessionFromToken(token);
}
