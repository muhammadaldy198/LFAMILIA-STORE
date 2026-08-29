"use client";

import { useState } from "react";
import type { StorefrontSettings } from "@/lib/store-data";

export function StoreBrand({ settings, compact = false }: { settings: StorefrontSettings; compact?: boolean }) {
  const [failedUrl, setFailedUrl] = useState<string | null>(null);
  return (
    <>
      <span className={`${compact ? "size-9" : "size-10"} grid shrink-0 place-items-center overflow-hidden rounded-xl bg-[#b9ff35] text-xs font-black text-[#091006] shadow-[0_0_24px_rgba(185,255,53,0.18)]`}>
        {settings.logoUrl && failedUrl !== settings.logoUrl ? (
          // eslint-disable-next-line @next/next/no-img-element
          <img key={settings.logoUrl} src={settings.logoUrl} alt={`Logo ${settings.storeName}`} className="size-full object-cover" onError={() => setFailedUrl(settings.logoUrl ?? null)} />
        ) : settings.storeShortName}
      </span>
      <span className="leading-none"><strong className="block max-w-36 truncate text-xs tracking-[0.1em] text-white">{settings.storeName.replace(/\s+STORE$/i, "")}</strong><span className="mt-1 block text-[9px] font-semibold tracking-[0.28em] text-white/40">STORE</span></span>
    </>
  );
}
