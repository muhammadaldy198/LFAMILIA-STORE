import { CheckoutUiEnhancer } from "@/components/checkout-ui-enhancer";

export default function CheckoutLayout({ children }: { children: React.ReactNode }) {
  return (
    <>
      <CheckoutUiEnhancer />
      {children}
    </>
  );
}
