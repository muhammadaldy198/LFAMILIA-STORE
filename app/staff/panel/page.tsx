import { cookies } from "next/headers";
import { redirect } from "next/navigation";
import { AdminDashboard } from "@/components/admin-dashboard";

export default async function StaffPanelPage() {
  const cookieStore = await cookies();
  if (!cookieStore.get("lfamilia_admin_session")) {
    redirect("/staff/panel/login");
  }

  return <AdminDashboard expectedRole="staff" />;
}
