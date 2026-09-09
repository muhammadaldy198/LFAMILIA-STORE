"use client";

import { useEffect, useRef, useState } from "react";

declare global {
  interface Window {
    turnstile?: {
      render(container: HTMLElement, options: {
        sitekey: string;
        callback(token: string): void;
        "expired-callback"(): void;
        "error-callback"(): void;
        theme?: "dark" | "light" | "auto";
        size?: "normal" | "compact";
      }): string;
      remove(widgetId: string): void;
    };
  }
}

export function TurnstileWidget({
  onToken,
}: {
  onToken(token: string): void;
}) {
  const containerRef = useRef<HTMLDivElement | null>(null);
  const [siteKey, setSiteKey] = useState<string | null>(null);

  useEffect(() => {
    let active = true;
    void fetch("/api/security/turnstile", { cache: "no-store" })
      .then(async (response) => response.ok ? response.json() : null)
      .then((data: { enabled?: boolean; siteKey?: string | null } | null) => {
        if (!active) return;
        setSiteKey(data?.enabled && data.siteKey ? data.siteKey : null);
      })
      .catch(() => setSiteKey(null));
    return () => { active = false; };
  }, []);

  useEffect(() => {
    const container = containerRef.current;
    if (!siteKey || !container) return;
    let disposed = false;
    let widgetId: string | null = null;

    const render = () => {
      if (disposed || widgetId || !window.turnstile) return;
      widgetId = window.turnstile.render(container, {
        sitekey: siteKey,
        theme: "dark",
        size: "normal",
        callback: (token) => onToken(token),
        "expired-callback": () => onToken(""),
        "error-callback": () => onToken(""),
      });
    };

    const existing = document.querySelector<HTMLScriptElement>('script[data-lf-turnstile="true"]');
    if (window.turnstile) render();
    else if (existing) existing.addEventListener("load", render, { once: true });
    else {
      const script = document.createElement("script");
      script.src = "https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit";
      script.async = true;
      script.defer = true;
      script.dataset.lfTurnstile = "true";
      script.addEventListener("load", render, { once: true });
      document.head.appendChild(script);
    }

    return () => {
      disposed = true;
      if (widgetId && window.turnstile) window.turnstile.remove(widgetId);
      container.replaceChildren();
    };
  }, [siteKey, onToken]);

  if (!siteKey) return null;
  return <div className="overflow-hidden rounded-xl border border-white/[0.08] bg-black/10 p-2"><div ref={containerRef} /></div>;
}
