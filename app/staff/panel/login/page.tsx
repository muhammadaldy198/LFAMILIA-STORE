import { PanelLogin } from "@/components/panel-login";

export default async function StaffPanelLoginPage({
  searchParams,
}: {
  searchParams: Promise<{ error?: string }>;
}) {
  const params = await searchParams;
  return <PanelLogin role="staff" initialError={params.error ?? ""} />;
}
