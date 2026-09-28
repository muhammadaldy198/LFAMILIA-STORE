import { redirect } from "next/navigation";
import { PanelLogin } from "@/components/panel-login";
import { getVpsPanelSession } from "@/lib/server/vps-panel-session";

export default async function PanelLoginPage({
  searchParams,
}: {
  searchParams: Promise<{ error?: string }>;
}) {
  const session = await getVpsPanelSession();
  if (session?.role === "staff") redirect("/staff/panel");

  const params = await searchParams;
  return <PanelLogin role="staff" initialError={params.error ?? ""} />;
}
