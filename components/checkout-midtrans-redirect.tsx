"use client";

import { useEffect } from "react";

function findMidtransPaymentUrl() {
  for (const anchor of Array.from(document.querySelectorAll<HTMLAnchorElement>("a[href]"))) {
    try {
      const url = new URL(anchor.href, window.location.origin);
      if (
        url.protocol === "https:" &&
        (url.hostname === "midtrans.com" || url.hostname.endsWith(".midtrans.com"))
      ) {
        return url.toString();
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
        const paymentUrl = findMidtransPaymentUrl();
        if (!paymentUrl) return;
        redirected = true;
        window.location.assign(paymentUrl);
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
