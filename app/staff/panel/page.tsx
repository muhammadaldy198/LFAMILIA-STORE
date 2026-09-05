import { cookies } from "next/headers";
import { redirect } from "next/navigation";
import { AdminDashboard } from "@/components/admin-dashboard";
import { getPanelSessionFromToken, PANEL_COOKIE_NAME } from "@/lib/server/admin-auth";

export default async function StaffPanelPage() {
  const cookieStore = await cookies();
  const session = await getPanelSessionFromToken(cookieStore.get(PANEL_COOKIE_NAME)?.value);

  if (!session || session.role !== "staff") {
    redirect("/staff/panel/login");
  }

  return <AdminDashboard expectedRole="staff" initialSession={session} />;
}
