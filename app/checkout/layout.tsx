import { CheckoutUiEnhancer } from "@/components/checkout-ui-enhancer";
import { CheckoutSpacingTuning } from "@/components/checkout-spacing-tuning";
import { CheckoutSavedGameAccounts } from "@/components/checkout-saved-game-accounts";

export default function CheckoutLayout({ children }: { children: React.ReactNode }) {
  return (
    <>
      <style>{`
        @media (min-width: 640px) {
          main section.relative.z-10 > div.pl-28.pt-1 {
            transform: translateY(-0.75rem);
          }
        }
      `}</style>
      <CheckoutUiEnhancer />
      <CheckoutSpacingTuning />
      <CheckoutSavedGameAccounts />
      {children}
    </>
  );
}
