"use client";

import { useEffect } from "react";

const orderReference = /LF(?:-\d{8}-[A-F0-9]{8,12}|\d{6}(?:[A-F0-9]{12}|[A-F0-9]{32}))/g;

function snapTokenFromUrl(value: string) {
  try {
    const url = new URL(value);
    const parts = url.pathname.split("/").filter(Boolean);
    const redirectionIndex = parts.lastIndexOf("redirection");
    if (redirectionIndex >= 0 && parts[redirectionIndex + 1])
      return decodeURIComponent(parts[redirectionIndex + 1]);
    return null;
  } catch {
    return null;
  }
}

function findMidtransPayment() {
  for (const anchor of Array.from(document.querySelectorAll<HTMLAnchorElement>("a[href]"))) {
    try {
      const url = new URL(anchor.href, window.location.origin);
      if (
        url.protocol === "https:" &&
        (url.hostname === "midtrans.com" || url.hostname.endsWith(".midtrans.com"))
      ) {
        const matches = document.body.textContent?.match(orderReference) ?? [];
        const referenceId = matches.at(-1);
        if (!referenceId) return null;
        return {
          paymentUrl: url.toString(),
          invoice: referenceId,
          snapToken: snapTokenFromUrl(url.toString()),
        };
      }
    } catch {
      // Abaikan href yang tidak valid.
    }
  }
  return null;
}

export function CheckoutMidtransRedirect() {
  useEffect(() => {
    let redirected = false;
    let frame = 0;

    function check() {
      if (redirected || frame) return;
      frame = window.requestAnimationFrame(() => {
        frame = 0;
        const payment = findMidtransPayment();
        if (!payment) return;
        redirected = true;
        try {
          window.sessionStorage.setItem(
            `lfamilia-payment:${payment.invoice}`,
            payment.paymentUrl,
          );
          if (payment.snapToken)
            window.sessionStorage.setItem(
              `lfamilia-snap-token:${payment.invoice}`,
              payment.snapToken,
            );
        } catch {
          // Session storage hanya fallback, bukan syarat pembayaran.
        }
        const params = new URLSearchParams({ invoice: payment.invoice });
        if (payment.snapToken) params.set("token", payment.snapToken);
        window.location.assign(`/payment?${params.toString()}`);
      });
    }

    const observer = new MutationObserver(check);
    observer.observe(document.body, { childList: true, subtree: true });
    check();

    return () => {
      observer.disconnect();
      if (frame) window.cancelAnimationFrame(frame);
    };
  }, []);

  return null;
}
