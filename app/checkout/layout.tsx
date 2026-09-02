import { CheckoutUiEnhancer } from "@/components/checkout-ui-enhancer";
import { CheckoutSpacingTuning } from "@/components/checkout-spacing-tuning";
import { CheckoutPackageTabs } from "@/components/checkout-package-tabs";

export default function CheckoutLayout({ children }: { children: React.ReactNode }) {
  return (
    <>
      <CheckoutUiEnhancer />
      <CheckoutSpacingTuning />
      <CheckoutPackageTabs />
      {children}
    </>
  );
}
