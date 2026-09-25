export default function CheckoutLayout({ children }: { children: React.ReactNode }) {
  return (
    <>
      <style>{`
        [data-lf-checkout-banner-image="true"] {
          object-fit: cover !important;
          object-position: 50% 50% !important;
          width: 100% !important;
          height: 100% !important;
        }

        [data-lf-account-grid="true"] {
          grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
          align-items: end !important;
        }

        [data-lf-product-features="true"] > span {
          display: flex !important;
          align-items: center !important;
          justify-content: center !important;
          gap: 0.48rem !important;
          white-space: nowrap;
          font-weight: 600;
        }

        [data-lf-product-features="true"] > span > svg {
          margin: 0 !important;
          flex-shrink: 0;
        }

        @media (max-width: 639px) {
          [data-lf-checkout-banner="true"] {
            width: calc(100% + 2rem) !important;
            height: auto !important;
            aspect-ratio: 16 / 9 !important;
          }

          [data-lf-product-hero="true"] {
            min-height: 7.5rem !important;
            padding-top: 0.55rem !important;
            padding-bottom: 0.55rem !important;
          }

          [data-lf-product-art="true"] {
            width: 8.5rem !important;
            height: 8.5rem !important;
            top: -4.5rem !important;
            left: 1.35rem !important;
            border-radius: 1.15rem !important;
            border-width: 3px !important;
            box-shadow: 0 12px 30px rgba(0, 0, 0, 0.38) !important;
          }

          [data-lf-product-info="true"] {
            padding-left: 10.85rem !important;
            padding-top: 1.05rem !important;
          }

          [data-lf-product-info="true"] h1 {
            font-size: 1.04rem !important;
            line-height: 1.15 !important;
            letter-spacing: 0.045em !important;
          }

          [data-lf-product-info="true"] p {
            margin-top: 0.42rem !important;
            font-size: 0.76rem !important;
            line-height: 1.15 !important;
          }

          [data-lf-product-features="true"] {
            left: 1rem !important;
            right: 1rem !important;
            bottom: 0.85rem !important;
            display: grid !important;
            grid-template-columns: repeat(3, minmax(0, 1fr)) !important;
            gap: 0.2rem !important;
            font-size: 0.62rem !important;
          }

          [data-lf-product-features="true"] > span > svg {
            width: 1rem !important;
            height: 1rem !important;
          }

          [data-lf-account-grid="true"] {
            gap: 0.65rem !important;
          }

          [data-lf-account-grid="true"] input {
            min-width: 0 !important;
            width: 100% !important;
            font-size: 0.86rem !important;
          }
        }

        @media (min-width: 640px) {
          [data-lf-checkout-banner="true"] {
            aspect-ratio: 16 / 7 !important;
            height: auto !important;
          }

          [data-lf-product-art="true"] {
            width: 9.25rem !important;
            height: 9.25rem !important;
            top: -5rem !important;
            border-radius: 1.2rem !important;
          }

          [data-lf-product-info="true"] {
            padding-left: 11.5rem !important;
            padding-top: 2.7rem !important;
            transform: translateY(-0.75rem);
          }
        }

        @media (min-width: 1024px) {
          [data-lf-checkout-banner="true"] {
            width: 100vw !important;
            max-width: none !important;
            margin-left: calc(50% - 50vw) !important;
            margin-right: calc(50% - 50vw) !important;
          }
        }
      `}</style>
      {children}
    </>
  );
}
