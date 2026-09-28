import { cookies } from "next/headers";
import { redirect } from "next/navigation";
import { PanelLogin } from "@/components/panel-login";
import { getRuntimePanelSession, PANEL_COOKIE_NAME } from "@/lib/server/panel-session-runtime";

export default async function PanelLoginPage({
  searchParams,
}: {
  searchParams: Promise<{ error?: string }>;
}) {
  const cookieStore = await cookies();
  const session = await getRuntimePanelSession(cookieStore.get(PANEL_COOKIE_NAME)?.value);
  if (session && (session.role === "super_admin" || session.role === "admin")) redirect("/admin/panel");

  const params = await searchParams;
  return <PanelLogin role="owner" initialError={params.error ?? ""} />;
}
