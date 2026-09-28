import { redirect } from "next/navigation";
import { PanelLogin } from "@/components/panel-login";
import { getVpsPanelSession } from "@/lib/server/vps-panel-session";

export default async function PanelLoginPage({
  searchParams,
}: {
  searchParams: Promise<{ error?: string }>;
}) {
  const session = await getVpsPanelSession();
  if (session && (session.role === "super_admin" || session.role === "admin")) redirect("/admin/panel");

  const params = await searchParams;
  return <PanelLogin role="owner" initialError={params.error ?? ""} />;
}
