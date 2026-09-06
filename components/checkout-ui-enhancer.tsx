"use client";

import { useEffect } from "react";

type ExtraNicknameState = {
  status: "idle" | "loading" | "success" | "error";
  key?: string;
  nickname?: string;
  country?: string | null;
  message?: string;
};

const additionalNicknameGames = new Set([
  "pubg-mobile",
  "honor-of-kings",
  "call-of-duty-mobile",
  "wild-rift",
  "arena-of-valor",
  "fc-mobile",
  "efootball",
  "point-blank",
]);

export function CheckoutUiEnhancer() {
  useEffect(() => {
    let disposed = false;
    let frame = 0;
    let nicknameTimer = 0;
    let nicknameController: AbortController | null = null;
    let extraNickname: ExtraNicknameState = { status: "idle" };
    const wiredNicknameInputs = new WeakSet<HTMLInputElement>();
    const wiredWhatsappInputs = new WeakSet<HTMLInputElement>();
    ensureStyles();

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
      enforceWhatsappDestination(form);
      enhanceAdditionalNickname(form);
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

    function enforceWhatsappDestination(form: HTMLFormElement) {
      const firstSection = form.querySelector<HTMLElement>("section");
      if (!firstSection) return;

      const destinationLabel = Array.from(firstSection.querySelectorAll<HTMLLabelElement>("label")).find(
        (label) => label.textContent?.trim().toLowerCase().startsWith("nomor whatsapp"),
      );
      const input = destinationLabel?.querySelector<HTMLInputElement>("input");
      if (!input) return;

      input.inputMode = "tel";
      input.autocomplete = "tel";
      input.placeholder = "Contoh: 081234567890";
      input.maxLength = 17;
      input.setAttribute("pattern", "\\+?[0-9]{8,16}");

      if (wiredWhatsappInputs.has(input)) return;
      wiredWhatsappInputs.add(input);
      input.addEventListener("input", () => {
        const original = input.value;
        const hasPlus = original.trim().startsWith("+");
        const digits = original.replace(/\D/g, "").slice(0, 16);
        const next = `${hasPlus ? "+" : ""}${digits}`;
        if (original !== next) input.value = next;
      });
    }

    function enhanceAdditionalNickname(form: HTMLFormElement) {
      const productSlug = new URL(window.location.href).searchParams.get("product") ?? "";
      if (!additionalNicknameGames.has(productSlug)) return;

      const firstSection = form.querySelector<HTMLElement>("section");
      if (!firstSection) return;

      const inputs = Array.from(firstSection.querySelectorAll<HTMLInputElement>("input.checkout-input"));
      const destinationInput = inputs[0];
      if (!destinationInput) return;

      const serverInput = inputs.find((input) => input !== destinationInput && input.inputMode === "numeric");
      let resultRow = firstSection.querySelector<HTMLElement>("[data-lf-extra-nickname]");
      if (!resultRow) {
        resultRow = Array.from(firstSection.querySelectorAll<HTMLParagraphElement>("p")).find((paragraph) =>
          paragraph.textContent?.toLowerCase().includes("verifikasi nickname otomatis belum tersedia"),
        );
        if (!resultRow) return;
        resultRow.dataset.lfExtraNickname = "true";
      }

      const key = `${productSlug}:${destinationInput.value.trim()}:${serverInput?.value.trim() ?? ""}`;
      renderExtraNickname(resultRow, extraNickname.key === key ? extraNickname : { status: "idle" });

      const wire = (input: HTMLInputElement) => {
        if (wiredNicknameInputs.has(input)) return;
        wiredNicknameInputs.add(input);
        input.addEventListener("input", requestExtraNicknameLookup);
      };
      wire(destinationInput);
      if (serverInput) wire(serverInput);
    }

    function requestExtraNicknameLookup() {
      window.clearTimeout(nicknameTimer);
      nicknameController?.abort();
      nicknameController = null;

      const productSlug = new URL(window.location.href).searchParams.get("product") ?? "";
      if (!additionalNicknameGames.has(productSlug)) return;

      const form = document.querySelector<HTMLFormElement>("#checkout-form");
      const firstSection = form?.querySelector<HTMLElement>("section");
      if (!firstSection) return;
      const inputs = Array.from(firstSection.querySelectorAll<HTMLInputElement>("input.checkout-input"));
      const destinationInput = inputs[0];
      if (!destinationInput) return;
      const serverInput = inputs.find((input) => input !== destinationInput && input.inputMode === "numeric");
      const userId = destinationInput.value.trim();
      const server = serverInput?.value.trim() ?? "";
      const key = `${productSlug}:${userId}:${server}`;

      if (userId.length < 2) {
        extraNickname = { status: "idle", key };
        schedule();
        return;
      }

      nicknameTimer = window.setTimeout(async () => {
        extraNickname = { status: "loading", key };
        schedule();
        const controller = new AbortController();
        nicknameController = controller;

        try {
          const response = await fetch("/api/nickname", {
            method: "POST",
            headers: { "content-type": "application/json" },
            body: JSON.stringify({
              game: productSlug,
              userId,
              server: server || undefined,
            }),
            signal: controller.signal,
          });
          const data = (await response.json()) as {
            nickname?: string;
            country?: string | null;
            error?: string;
          };
          if (!response.ok || !data.nickname) {
            throw new Error(data.error ?? "Nickname tidak ditemukan.");
          }
          extraNickname = {
            status: "success",
            key,
            nickname: data.nickname,
            country: data.country,
          };
        } catch (error) {
          if (controller.signal.aborted) return;
          extraNickname = {
            status: "error",
            key,
            message: error instanceof Error ? error.message : "Nickname gagal diperiksa.",
          };
        } finally {
          if (nicknameController === controller) nicknameController = null;
          schedule();
        }
      }, 700);
    }

    function renderExtraNickname(row: HTMLElement, state: ExtraNicknameState) {
      const signature = `${state.status}:${state.nickname ?? ""}:${state.country ?? ""}:${state.message ?? ""}`;
      if (row.dataset.lfNicknameSignature === signature) return;
      row.dataset.lfNicknameSignature = signature;

      if (state.status === "success") {
        row.className = "mt-3 rounded-lg border border-[#b9ff35]/25 bg-[#b9ff35]/[0.06] p-2.5 text-[10px] font-semibold leading-4 text-[#d8ff8d]";
        row.textContent = `✓ Nickname: ${state.nickname}${state.country ? ` • ${state.country}` : ""}`;
        return;
      }
      if (state.status === "loading") {
        row.className = "mt-3 rounded-lg border border-white/[0.08] bg-white/[0.025] p-2.5 text-[10px] leading-4 text-white/50";
        row.textContent = "Memeriksa nickname melalui Melostore…";
        return;
      }
      if (state.status === "error") {
        row.className = "mt-3 rounded-lg border border-amber-300/20 bg-amber-300/[0.05] p-2.5 text-[10px] leading-4 text-amber-100/70";
        row.textContent = `Nickname belum terverifikasi: ${state.message ?? "Periksa kembali ID."}`;
        return;
      }

      row.className = "mt-3 rounded-lg border border-white/[0.07] bg-white/[0.025] p-2.5 text-[10px] leading-4 text-white/40";
      row.textContent = "Nickname akan diperiksa otomatis melalui Melostore setelah ID diisi.";
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
      window.clearTimeout(nicknameTimer);
      nicknameController?.abort();
      if (frame) window.cancelAnimationFrame(frame);
    };
  }, []);

  return null;
}
