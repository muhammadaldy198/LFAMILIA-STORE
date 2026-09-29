import { redirect } from "next/navigation";
import { AdminDashboard } from "@/components/admin-dashboard";
import { getVpsPanelSession } from "@/lib/server/vps-panel-session";

export default async function StaffPanelPage() {
  const session = await getVpsPanelSession();

  if (!session || session.role !== "staff") {
    redirect("/staff/panel/login");
  }

  return <AdminDashboard expectedRole="staff" initialSession={session} />;
}
