"use client";

import { useEffect } from "react";

export function CheckoutSpacingTuning() {
  useEffect(() => {
    const styleId = "lf-checkout-spacing-tuning";
    let style = document.getElementById(styleId) as HTMLStyleElement | null;

    if (!style) {
      style = document.createElement("style");
      style.id = styleId;
      style.textContent = `
        @media (max-width: 639px) {
          [data-lf-product-hero="true"] {
            min-height: 7.5rem !important;
            padding-top: 0.55rem !important;
            padding-bottom: 0.55rem !important;
          }

          [data-lf-product-info="true"] {
            padding-top: 1.7rem !important;
          }

          [data-lf-product-features="true"] {
            bottom: 0.85rem !important;
          }
        }
      `;
      document.head.appendChild(style);
    }

    function removeProviderName() {
      const form = document.querySelector<HTMLFormElement>("#checkout-form");
      const firstSection = form?.querySelector<HTMLElement>("section");
      if (!firstSection) return;

      const walker = document.createTreeWalker(firstSection, NodeFilter.SHOW_TEXT);
      let node = walker.nextNode();
      while (node) {
        const value = node.nodeValue;
        if (value?.toLowerCase().includes("melostore")) {
          node.nodeValue = value
            .replace(/ melalui Melostore/gi, "")
            .replace(/ di Melostore/gi, "")
            .replace(/Melostore/gi, "layanan verifikasi");
        }
        node = walker.nextNode();
      }
    }

    const observer = new MutationObserver(removeProviderName);
    observer.observe(document.body, {
      childList: true,
      subtree: true,
      characterData: true,
    });
    removeProviderName();

    return () => {
      observer.disconnect();
      document.getElementById(styleId)?.remove();
    };
  }, []);

  return null;
}
