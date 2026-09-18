import { CustomerAccount } from "@/components/customer-account";
import { CustomerMembershipSummary } from "@/components/customer-membership-summary";
import { StoreLayout } from "@/components/store-layout";

export default async function AccountPage({
  searchParams,
}: {
  searchParams: Promise<{ mode?: string; auth?: string }>;
}) {
  const params = await searchParams;
  const initialMode = params.mode === "register" ? "register" : "login";
  const initialError = params.auth === "google-unavailable"
    ? "Login Google belum diaktifkan oleh pemilik toko."
    : params.auth === "google-cancelled"
      ? "Login Google dibatalkan."
      : params.auth === "google-error"
        ? "Login Google gagal. Silakan coba lagi."
        : "";

  return (
    <StoreLayout>
      <main className="mx-auto min-h-[70vh] max-w-7xl px-4 py-10 sm:px-6 lg:px-8">
        <CustomerMembershipSummary />
        <CustomerAccount initialMode={initialMode} initialError={initialError} />
      </main>
    </StoreLayout>
  );
}
