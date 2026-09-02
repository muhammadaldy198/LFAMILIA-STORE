import { CheckoutUiEnhancer } from "@/components/checkout-ui-enhancer";
import { CheckoutSpacingTuning } from "@/components/checkout-spacing-tuning";

export default function CheckoutLayout({ children }: { children: React.ReactNode }) {
  return (
    <>
      <CheckoutUiEnhancer />
      <CheckoutSpacingTuning />
      {children}
    </>
  );
}
