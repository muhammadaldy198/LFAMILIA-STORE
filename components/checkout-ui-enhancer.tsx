"use client";

import { useEffect } from "react";

type PaymentChannelPreview = {
  method?: string;
  channel?: string;
  name?: string;
  imageUrl?: string;
};

const paymentMethodByLabel: Record<string, string> = {
  qris: "qris",
  "e-wallet": "ewallet",
  ewallet: "ewallet",
  "virtual account": "va",
  va: "va",
};

export function CheckoutUiEnhancer() {
  useEffect(() => {
    let disposed = false;
    let frame = 0;
    let channels: PaymentChannelPreview[] = [];
    const wiredHeaders = new WeakSet<HTMLButtonElement>();

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
      const form = document.querySelector<HTMLFormElement>("#checkout-form");
      if (!form) return;

      makeAccountFieldsParallel(form);
      enhancePaymentGroups(form);
    }

    function makeAccountFieldsParallel(form: HTMLFormElement) {
      const firstSection = form.querySelector<HTMLElement>("section");
      if (!firstSection) return;

      const grids = Array.from(firstSection.querySelectorAll<HTMLElement>("div.grid"));
      const accountGrid = grids.find((grid) => {
        const directLabels = Array.from(grid.children).filter(
          (child) => child.tagName.toLowerCase() === "label",
        );
        return directLabels.length === 2;
      });

      if (!accountGrid) return;
      accountGrid.style.gridTemplateColumns = "repeat(2, minmax(0, 1fr))";
      accountGrid.style.alignItems = "end";
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

        const label = header.querySelector("strong")?.textContent?.trim() ?? "";
        const normalized = label.toLowerCase();
        if (normalized.includes("koin lfamilia")) return;

        const method = resolveMethod(normalized);
        const open = node.className.includes("border-[#b9ff35]/60");
        header.setAttribute("aria-expanded", String(open));

        let arrow = header.querySelector<HTMLElement>("[data-lf-payment-chevron]");
        if (!arrow) {
          arrow = document.createElement("span");
          arrow.dataset.lfPaymentChevron = "true";
          arrow.className = "ml-1 grid size-6 shrink-0 place-items-center rounded-md border border-white/10 bg-white/[0.04] text-[11px] font-black text-white/60 transition";
          header.appendChild(arrow);
        }
        arrow.textContent = open ? "▲" : "▼";
        arrow.setAttribute("aria-hidden", "true");

        if (!wiredHeaders.has(header)) {
          wiredHeaders.add(header);
          header.addEventListener("click", () => window.setTimeout(schedule, 0));
        }

        const preview = Array.from(node.children).find(
          (child) => child instanceof HTMLDivElement && child !== header && child.className.includes("border-t"),
        ) as HTMLDivElement | undefined;

        if (preview && method) renderLogoOnlyPreview(preview, method);
      });
    }

    function resolveMethod(label: string) {
      if (label.includes("qris")) return "qris";
      if (label.includes("e-wallet") || label.includes("ewallet")) return "ewallet";
      if (label.includes("virtual account") || label === "va") return "va";
      return paymentMethodByLabel[label] ?? "";
    }

    function renderLogoOnlyPreview(preview: HTMLDivElement, method: string) {
      const withImages = channels
        .filter((channel) => channel.method === method && Boolean(channel.imageUrl))
        .slice(0, 7);

      if (!withImages.length) return;

      const signature = withImages.map((channel) => `${channel.channel}:${channel.imageUrl}`).join("|");
      if (preview.dataset.lfLogoSignature === signature) return;

      preview.replaceChildren();
      preview.dataset.lfLogoSignature = signature;
      preview.style.display = "flex";
      preview.style.alignItems = "center";
      preview.style.gap = "0.5rem";

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

    const observer = new MutationObserver(schedule);
    observer.observe(document.body, { childList: true, subtree: true, attributes: true, attributeFilter: ["class"] });
    schedule();

    return () => {
      disposed = true;
      observer.disconnect();
      if (frame) window.cancelAnimationFrame(frame);
    };
  }, []);

  return null;
}
