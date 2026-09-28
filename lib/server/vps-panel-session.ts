import "server-only";
import { cookies } from "next/headers";

export type VpsPanelSession = {
  id: number;
  email: string;
  name: string;
  role: "super_admin" | "admin" | "staff";
};

const PANEL_SESSION_URL =
  process.env.LFAMILIA_LARAVEL_INTERNAL_URL?.replace(/\/$/, "") ||
  "http://127.0.0.1:8080";

export async function getVpsPanelSession(): Promise<VpsPanelSession | null> {
  const cookieStore = await cookies();
  const cookieHeader = cookieStore.toString();
  if (!cookieHeader) return null;

  try {
    const response = await fetch(`${PANEL_SESSION_URL}/api/admin/session`, {
      headers: { cookie: cookieHeader, accept: "application/json" },
      cache: "no-store",
    });
    if (!response.ok) return null;
    const payload = (await response.json()) as { session?: VpsPanelSession };
    return payload.session ?? null;
  } catch {
    return null;
  }
}
