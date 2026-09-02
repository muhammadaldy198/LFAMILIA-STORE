"use client";

import { useEffect } from "react";
import { useStoreProducts } from "@/hooks/use-store-products";

const INTERNAL_VOUCHER_DESTINATION = "00000000";

function setReactInputValue(input: HTMLInputElement, value: string) {
  const setter = Object.getOwnPropertyDescriptor(
    HTMLInputElement.prototype,
    "value",
  )?.set;
  setter?.call(input, value);
  input.dispatchEvent(new Event("input", { bubbles: true }));
}

function sectionTitle(section: HTMLElement) {
  return section.querySelector("h2")?.textContent?.trim() ?? "";
}

function setStepNumber(section: HTMLElement, number: number) {
  const heading = section.querySelector("h2");
  const step = heading?.parentElement?.parentElement;
  const badge = step?.querySelector<HTMLElement>(":scope > span");
  const next = String(number);
  if (badge && badge.textContent !== next) badge.textContent = next;
}

export function CheckoutVoucherMode() {
  const { products } = useStoreProducts();

  useEffect(() => {
    let frame = 0;
    let disposed = false;

    function schedule() {
      if (disposed || frame) return;
      frame = window.requestAnimationFrame(() => {
        frame = 0;
        applyVoucherMode();
      });
    }

    function applyVoucherMode() {
      const form = document.querySelector<HTMLFormElement>("#checkout-form");
      if (!form) return;

      const slug = new URL(window.location.href).searchParams.get("product");
      const product = products.find((item) => item.slug === slug) ?? products[0];
      const isVoucher = product?.category.trim().toLowerCase() === "voucher";
      const sections = Array.from(form.querySelectorAll<HTMLElement>(":scope > section"));
      const accountSection = sections.find(
        (section) => sectionTitle(section) === "Masukkan Data Akun",
      );

      if (!accountSection) return;

      const destinationInput = accountSection.querySelector<HTMLInputElement>(
        "input.checkout-input",
      );

      if (!isVoucher) {
        accountSection.style.removeProperty("display");
        accountSection.removeAttribute("data-lf-voucher-hidden");
        if (
          destinationInput?.dataset.lfVoucherInternal === "true" &&
          destinationInput.value === INTERNAL_VOUCHER_DESTINATION
        ) {
          delete destinationInput.dataset.lfVoucherInternal;
          setReactInputValue(destinationInput, "");
        }
        return;
      }

      if (destinationInput && destinationInput.value !== INTERNAL_VOUCHER_DESTINATION) {
        destinationInput.dataset.lfVoucherInternal = "true";
        setReactInputValue(destinationInput, INTERNAL_VOUCHER_DESTINATION);
      }

      if (accountSection.dataset.lfVoucherHidden !== "true") {
        accountSection.dataset.lfVoucherHidden = "true";
        accountSection.style.display = "none";
      }

      const visibleSteps = sections.filter(
        (section) => section !== accountSection && Boolean(section.querySelector("h2")),
      );
      visibleSteps.forEach((section, index) => setStepNumber(section, index + 1));

      for (const dialog of Array.from(document.querySelectorAll<HTMLElement>("[role='dialog']"))) {
        if (!dialog.textContent?.includes("Buat Pesanan")) continue;

        for (const paragraph of Array.from(dialog.querySelectorAll<HTMLParagraphElement>("p"))) {
          if (paragraph.textContent?.includes("Pastikan data akun dan produk")) {
            const next = "Pastikan produk, nominal, dan pembayaran yang kamu pilih sudah sesuai.";
            if (paragraph.textContent !== next) paragraph.textContent = next;
          }
        }

        const summary = dialog.querySelector("dl");
        if (!summary) continue;
        for (const row of Array.from(summary.children)) {
          if (!(row instanceof HTMLElement)) continue;
          const label = row.querySelector("dt")?.textContent?.trim();
          if (
            (label === "Username" || label === "ID" || label === "Server") &&
            row.style.display !== "none"
          ) {
            row.style.display = "none";
          }
        }
      }
    }

    const observer = new MutationObserver(schedule);
    observer.observe(document.body, { childList: true, subtree: true });
    schedule();

    return () => {
      disposed = true;
      observer.disconnect();
      if (frame) window.cancelAnimationFrame(frame);
    };
  }, [products]);

  return null;
}
