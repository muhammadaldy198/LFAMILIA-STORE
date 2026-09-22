import { PasswordRecovery } from "@/components/password-recovery";
import { StoreLayout } from "@/components/store-layout";
import { noIndexMetadata } from "@/lib/seo";

export const metadata = noIndexMetadata("Reset Password");

export default function ResetPasswordPage() {
  return (
    <StoreLayout>
      <main className="mx-auto min-h-[70vh] max-w-7xl px-4 py-12 sm:px-6 lg:px-8">
        <PasswordRecovery mode="reset" />
      </main>
    </StoreLayout>
  );
}
