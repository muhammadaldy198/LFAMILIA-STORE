import { cookies } from "next/headers";
import { redirect } from "next/navigation";
import { AdminDashboard } from "@/components/admin-dashboard";
import { getRuntimePanelSession, PANEL_COOKIE_NAME } from "@/lib/server/panel-session-runtime";

export default async function AdminPanelPage() {
  const cookieStore = await cookies();
  const session = await getRuntimePanelSession(cookieStore.get(PANEL_COOKIE_NAME)?.value);

  if (!session || (session.role !== "super_admin" && session.role !== "admin")) {
    redirect("/admin/panel/login");
  }

  return <AdminDashboard expectedRole="backoffice" initialSession={session} />;
}
