"use client";

import { useEffect } from "react";

type PaymentChannelPreview = {
  method?: string;
  channel?: string;
  name?: string;
  imageUrl?: string;
};

export function CheckoutUiEnhancer() {
  useEffect(() => {
    let disposed = false;
    let frame = 0;
    let channels: PaymentChannelPreview[] = [];
    const wiredHeaders = new WeakSet<HTMLButtonElement>();
    const expandedMethods = new Set<string>();

    ensureStyles();

    void fetch("/api/payment-methods", { cache: "no-store" })
      .then(async (response) => {
        if (!response.ok) return;
        const data = (await response.json()) as { channels?: PaymentChannelPreview[] };
        channels = data.channels ?? [];
        schedule();
      })
      .catch(() => undefined);

    function schedule() {
      if (disposed || frame) return;
      frame = window.requestAnimationFrame(() => {
        frame = 0;
        enhanceCheckout();
      });
    }

    function enhanceCheckout() {
      const main = document.querySelector<HTMLElement>("main");
      const form = document.querySelector<HTMLFormElement>("#checkout-form");
      if (!main || !form) return;

      enhanceProductHero(main);
      makeAccountFieldsParallel(form);
      enhancePaymentGroups(form);
    }

    function enhanceProductHero(main: HTMLElement) {
      const directSections = Array.from(main.children).filter(
        (child): child is HTMLElement => child instanceof HTMLElement && child.tagName.toLowerCase() === "section",
      );
      if (directSections.length < 2) return;

      const banner = directSections[0];
      const hero = directSections[1];
      banner.dataset.lfCheckoutBanner = "true";
      hero.dataset.lfProductHero = "true";

      const bannerImage = banner.querySelector<HTMLImageElement>("img");
      if (bannerImage) {
        bannerImage.dataset.lfCheckoutBannerImage = "true";
        bannerImage.style.objectPosition = "center center";
      }

      const artwork = Array.from(hero.children).find(
        (child): child is HTMLElement => child instanceof HTMLElement && child.tagName.toLowerCase() === "span",
      );
      if (artwork) artwork.dataset.lfProductArt = "true";

      const info = Array.from(hero.children).find(
        (child): child is HTMLElement => child instanceof HTMLElement && child.querySelector("h1") !== null,
      );
      if (info) info.dataset.lfProductInfo = "true";

      const features = Array.from(hero.children).find(
        (child): child is HTMLElement =>
          child instanceof HTMLElement &&
          child.tagName.toLowerCase() === "div" &&
          child.querySelectorAll(":scope > span").length === 3,
      );
      if (features) features.dataset.lfProductFeatures = "true";
    }

    function makeAccountFieldsParallel(form: HTMLFormElement) {
      const firstSection = form.querySelector<HTMLElement>("section");
      if (!firstSection) return;

      const accountGrid = Array.from(firstSection.querySelectorAll<HTMLElement>("div.grid")).find((grid) => {
        const directLabels = Array.from(grid.children).filter(
          (child) => child.tagName.toLowerCase() === "label",
        );
        return directLabels.length === 2;
      });

      if (!accountGrid) return;
      accountGrid.dataset.lfAccountGrid = "true";
    }

    function enhancePaymentGroups(form: HTMLFormElement) {
      const sections = Array.from(form.querySelectorAll<HTMLElement>("section"));
      const paymentSection = sections.find((section) =>
        section.querySelector("h2")?.textContent?.toLowerCase().includes("pembayaran"),
      );
      if (!paymentSection) return;

      const groupsContainer = Array.from(paymentSection.querySelectorAll<HTMLElement>("div")).find(
        (element) => element.className.includes("space-y-2"),
      );
      if (!groupsContainer) return;

      Array.from(groupsContainer.children).forEach((node) => {
        if (!(node instanceof HTMLElement)) return;
        const header = Array.from(node.children).find(
          (child): child is HTMLButtonElement => child instanceof HTMLButtonElement,
        );
        if (!header) return;

        const label = header.querySelector("strong")?.textContent?.trim().toLowerCase() ?? "";
        if (label.includes("koin lfamilia")) return;

        const method = resolveMethod(label);
        if (!method) return;

        const selected = node.className.includes("border-[#b9ff35]/60");
        if (!selected) expandedMethods.delete(method);

        const directBorderRows = Array.from(node.children).filter(
          (child): child is HTMLDivElement =>
            child instanceof HTMLDivElement && child.className.includes("border-t"),
        );
        const preview = directBorderRows[0];
        const channelDropdown = directBorderRows.find((row) => row.className.includes("grid-cols"));

        if (preview) renderLogoOnlyPreview(preview, method);

        if (method === "qris") {
          header.removeAttribute("aria-expanded");
          header.querySelector("[data-lf-payment-chevron]")?.remove();
          if (channelDropdown) channelDropdown.style.display = "none";
          return;
        }

        const dropdownOpen = selected && expandedMethods.has(method);
        header.setAttribute("aria-expanded", String(dropdownOpen));

        let arrow = header.querySelector<HTMLElement>("[data-lf-payment-chevron]");
        if (!arrow) {
          arrow = document.createElement("span");
          arrow.dataset.lfPaymentChevron = "true";
          arrow.className = "ml-1 grid size-6 shrink-0 place-items-center rounded-md border border-white/10 bg-white/[0.04] text-white/60 transition";
          arrow.setAttribute("aria-hidden", "true");
          header.appendChild(arrow);
        }
        arrow.innerHTML = dropdownOpen
          ? '<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="m18 15-6-6-6 6"/></svg>'
          : '<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="m6 9 6 6 6-6"/></svg>';

        if (channelDropdown) channelDropdown.style.display = dropdownOpen ? "grid" : "none";

        if (!wiredHeaders.has(header)) {
          wiredHeaders.add(header);
          header.addEventListener("click", () => {
            const currentLabel = header.querySelector("strong")?.textContent?.trim().toLowerCase() ?? "";
            const currentMethod = resolveMethod(currentLabel);
            if (!currentMethod || currentMethod === "qris") return;

            if (expandedMethods.has(currentMethod)) expandedMethods.delete(currentMethod);
            else expandedMethods.add(currentMethod);
            window.setTimeout(schedule, 0);
          });
        }
      });
    }

    function resolveMethod(label: string) {
      if (label.includes("qris")) return "qris";
      if (label.includes("e-wallet") || label.includes("ewallet")) return "ewallet";
      if (label.includes("virtual account") || label === "va") return "va";
      return "";
    }

    function renderLogoOnlyPreview(preview: HTMLDivElement, method: string) {
      const withImages = channels
        .filter((channel) => channel.method === method && Boolean(channel.imageUrl))
        .slice(0, 8);

      const signature = withImages.map((channel) => `${channel.channel}:${channel.imageUrl}`).join("|");
      if (preview.dataset.lfLogoSignature === signature) return;

      preview.replaceChildren();
      preview.dataset.lfLogoSignature = signature;

      if (!withImages.length) {
        preview.style.display = "none";
        return;
      }

      preview.style.display = "flex";
      preview.style.alignItems = "center";
      preview.style.gap = "0.65rem";
      preview.style.minHeight = "2.25rem";

      withImages.forEach((channel) => {
        const img = document.createElement("img");
        img.src = channel.imageUrl!;
        img.alt = channel.name ?? "Metode pembayaran";
        img.title = channel.name ?? "";
        img.className = "h-5 max-w-14 object-contain";
        img.loading = "lazy";
        preview.appendChild(img);
      });
    }

    function ensureStyles() {
      if (document.getElementById("lf-checkout-visual-tuning")) return;
      const style = document.createElement("style");
      style.id = "lf-checkout-visual-tuning";
      style.textContent = `
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
            min-height: 8.75rem !important;
            padding-top: 0.75rem !important;
            padding-bottom: 0.75rem !important;
          }

          [data-lf-product-art="true"] {
            width: 8.5rem !important;
            height: 8.5rem !important;
            top: -4.5rem !important;
            left: 1.35rem !important;
            border-radius: 1.15rem !important;
            border-width: 3px !important;
            box-shadow: 0 12px 30px rgba(0,0,0,.38) !important;
          }

          [data-lf-product-info="true"] {
            padding-left: 10.85rem !important;
            padding-top: 2.7rem !important;
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
            bottom: 0.72rem !important;
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
          }
        }
      `;
      document.head.appendChild(style);
    }

    const observer = new MutationObserver(schedule);
    observer.observe(document.body, {
      childList: true,
      subtree: true,
      attributes: true,
      attributeFilter: ["class"],
    });
    schedule();

    return () => {
      disposed = true;
      observer.disconnect();
      if (frame) window.cancelAnimationFrame(frame);
    };
  }, []);

  return null;
}
