import { redirect } from "next/navigation";
import { AdminDashboard } from "@/components/admin-dashboard";
import { getVpsPanelSession } from "@/lib/server/vps-panel-session";

export default async function AdminPanelPage() {
  const session = await getVpsPanelSession();

  if (!session || (session.role !== "super_admin" && session.role !== "admin")) {
    redirect("/admin/panel/login");
  }

  return <AdminDashboard expectedRole="backoffice" initialSession={session} />;
}
