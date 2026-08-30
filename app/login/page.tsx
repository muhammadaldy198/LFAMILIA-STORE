import { CustomerAccount } from "@/components/customer-account";
import { StoreLayout } from "@/components/store-layout";

export default function LoginPage() {
  return <StoreLayout><main className="mx-auto min-h-[70vh] max-w-7xl px-4 py-12 sm:px-6 lg:px-8"><CustomerAccount initialMode="login" /></main></StoreLayout>;
}
