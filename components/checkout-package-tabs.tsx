"use client";

import { useEffect } from "react";

type PackageTabProduct = {
  slug: string;
  packages: Array<{
    id: string;
    group?: string;
  }>;
};

function groupName(value?: string) {
  return value?.trim() || "Umum";
}

export function CheckoutPackageTabs() {
  useEffect(() => {
    let disposed = false;
    let frame = 0;
    let products: PackageTabProduct[] = [];
    let activeGroup = "";

    void fetch("/api/products", { cache: "no-store" })
      .then(async (response) => {
        if (!response.ok) return;
        const data = (await response.json()) as { products?: PackageTabProduct[] };
        products = data.products ?? [];
        schedule();
      })
      .catch(() => undefined);

    function schedule() {
      if (disposed || frame) return;
      frame = window.requestAnimationFrame(() => {
        frame = 0;
        enhance();
      });
    }

    function enhance() {
      const productSlug = new URL(window.location.href).searchParams.get("product") ?? "";
      const product = products.find((item) => item.slug === productSlug);
      const form = document.querySelector<HTMLFormElement>("#checkout-form");
      if (!product || !form || !product.packages.length) return;

      const section = Array.from(form.querySelectorAll<HTMLElement>("section")).find((item) =>
        item.querySelector("h2")?.textContent?.toLowerCase().includes("pilih nominal"),
      );
      if (!section) return;

      const packageGrid = Array.from(section.querySelectorAll<HTMLElement>("div.grid")).find((grid) =>
        Array.from(grid.children).some((child) => child instanceof HTMLButtonElement),
      );
      if (!packageGrid) return;

      const packageButtons = Array.from(packageGrid.children).filter(
        (child): child is HTMLButtonElement => child instanceof HTMLButtonElement,
      );
      if (!packageButtons.length) return;

      const groups = Array.from(new Set(product.packages.map((item) => groupName(item.group))));
      let tabs = section.querySelector<HTMLElement>("[data-lf-package-tabs]");

      if (groups.length < 2) {
        tabs?.remove();
        packageButtons.forEach((button) => {
          button.style.display = "";
        });
        activeGroup = groups[0] ?? "";
        return;
      }

      if (!groups.includes(activeGroup)) {
        const selectedIndex = packageButtons.findIndex((button) =>
          button.className.includes("border-[#b9ff35]"),
        );
        activeGroup =
          selectedIndex >= 0 && product.packages[selectedIndex]
            ? groupName(product.packages[selectedIndex].group)
            : groups[0];
      }

      if (!tabs) {
        tabs = document.createElement("div");
        tabs.dataset.lfPackageTabs = "true";
        tabs.className = "mt-3 flex gap-2 overflow-x-auto pb-1";
        packageGrid.parentElement?.insertBefore(tabs, packageGrid);
      }

      const signature = `${groups.join("|")}::${activeGroup}`;
      if (tabs.dataset.signature !== signature) {
        tabs.dataset.signature = signature;
        tabs.replaceChildren();

        groups.forEach((group) => {
          const button = document.createElement("button");
          button.type = "button";
          button.textContent = group;
          button.className =
            group === activeGroup
              ? "shrink-0 rounded-lg border border-[#bca17d] bg-[#bca17d] px-3 py-2 text-[10px] font-black text-white"
              : "shrink-0 rounded-lg border border-white/[0.10] bg-white/[0.035] px-3 py-2 text-[10px] font-bold text-white/55 transition hover:border-white/20 hover:text-white";
          button.addEventListener("click", () => {
            if (group === activeGroup) return;
            activeGroup = group;
            const selectedIndex = packageButtons.findIndex((item) =>
              item.className.includes("border-[#b9ff35]"),
            );
            const selectedGroup =
              selectedIndex >= 0 && product.packages[selectedIndex]
                ? groupName(product.packages[selectedIndex].group)
                : "";

            applyVisibility(product, packageButtons);

            if (selectedIndex >= 0 && selectedGroup !== activeGroup) {
              const firstVisibleIndex = product.packages.findIndex(
                (item) => groupName(item.group) === activeGroup,
              );
              packageButtons[firstVisibleIndex]?.click();
            }
            schedule();
          });
          tabs!.appendChild(button);
        });
      }

      packageGrid.classList.toggle("mt-2", groups.length > 1);
      packageGrid.classList.toggle("mt-3", groups.length < 2);
      applyVisibility(product, packageButtons);
    }

    function applyVisibility(product: PackageTabProduct, buttons: HTMLButtonElement[]) {
      buttons.forEach((button, index) => {
        const packageItem = product.packages[index];
        if (!packageItem) return;
        button.style.display = groupName(packageItem.group) === activeGroup ? "" : "none";
      });
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
