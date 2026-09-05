import { PanelLogin } from "@/components/panel-login";

export default async function AdminPanelLoginPage({
  searchParams,
}: {
  searchParams: Promise<{ error?: string }>;
}) {
  const params = await searchParams;
  return <PanelLogin role="owner" initialError={params.error ?? ""} />;
}
