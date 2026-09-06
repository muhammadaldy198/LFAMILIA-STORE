"use client";

import { useEffect } from "react";

type SavedValue = { id: string; label: string; value: string };
type SavedAccount = { id: string; label: string; nickname: string | null; values: SavedValue[] };

function setReactInputValue(input: HTMLInputElement, value: string) {
  const descriptor = Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, "value");
  descriptor?.set?.call(input, value);
  input.dispatchEvent(new Event("input", { bubbles: true }));
  input.dispatchEvent(new Event("change", { bubbles: true }));
}

export function CheckoutSavedGameAccounts() {
  useEffect(() => {
    const product = new URL(window.location.href).searchParams.get("product")?.trim() ?? "";
    if (!product) return;
    let disposed = false;
    let accounts: SavedAccount[] = [];
    let observer: MutationObserver | null = null;

    function render() {
      if (disposed) return;
      const form = document.querySelector<HTMLFormElement>("#checkout-form");
      const section = form?.querySelector<HTMLElement>("section");
      if (!section) return;
      const existing = section.querySelector<HTMLElement>("[data-lf-saved-game-account]");
      if (!accounts.length) { existing?.remove(); return; }
      if (existing) return;

      const target = Array.from(section.querySelectorAll<HTMLElement>("div.grid")).find((grid) =>
        Array.from(grid.children).some((child) => child.tagName.toLowerCase() === "label"),
      );
      if (!target) return;

      const wrap = document.createElement("div");
      wrap.dataset.lfSavedGameAccount = "true";
      wrap.className = "mx-3 mt-3 rounded-lg border border-[#b9ff35]/18 bg-[#b9ff35]/[0.045] p-2.5";
      const label = document.createElement("label");
      label.className = "block text-[10px] font-bold text-[#d8ff8d]";
      label.textContent = "Akun game tersimpan";
      const select = document.createElement("select");
      select.className = "mt-1.5 h-9 w-full rounded-lg border border-white/10 bg-[#171c27] px-2.5 text-[11px] text-white outline-none";
      select.innerHTML = '<option value="">Isi manual</option>' + accounts.map((account) => `<option value="${account.id}">${escapeHtml(account.label)}${account.nickname ? ` · ${escapeHtml(account.nickname)}` : ""}</option>`).join("");
      select.addEventListener("change", () => {
        const account = accounts.find((item) => item.id === select.value);
        if (!account) return;
        const inputs = Array.from(section.querySelectorAll<HTMLInputElement>("input.checkout-input"));
        account.values.forEach((value, index) => {
          const input = inputs[index];
          if (input) setReactInputValue(input, value.value);
        });
      });
      label.appendChild(select);
      wrap.appendChild(label);
      target.parentElement?.insertBefore(wrap, target);
    }

    void fetch(`/api/account/game-accounts?product=${encodeURIComponent(product)}`, { cache: "no-store" })
      .then(async (response) => {
        if (!response.ok) return;
        const data = await response.json() as { accounts?: SavedAccount[] };
        accounts = data.accounts ?? [];
        render();
        observer = new MutationObserver(render);
        observer.observe(document.body, { childList: true, subtree: true });
      })
      .catch(() => undefined);

    return () => { disposed = true; observer?.disconnect(); };
  }, []);
  return null;
}

function escapeHtml(value: string) {
  return value.replace(/[&<>"']/g, (char) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" })[char] || char);
}
