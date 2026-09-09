"use client";

import Image from "next/image";
import { useState } from "react";

const PRIMARY_LOGO = "/lfamilia-admin-logo.webp";
const FALLBACK_LOGO = "/brand/lfamilia-pixel-logo.webp";

export function AdminBrandLogo({ primarySrc = PRIMARY_LOGO }: { primarySrc?: string }) {
  const [source, setSource] = useState(primarySrc);
  const [imageUnavailable, setImageUnavailable] = useState(false);

  function handleError() {
    if (source !== FALLBACK_LOGO) {
      setSource(FALLBACK_LOGO);
      return;
    }
    setImageUnavailable(true);
  }

  return (
    <span className="relative grid size-8 shrink-0 place-items-center overflow-hidden rounded-full bg-[#155eef] text-[9px] font-black text-white shadow-sm">
      <span aria-hidden="true">LF</span>
      {!imageUnavailable && (
        <Image
          key={source}
          src={source}
          alt="Logo LFAMILIA"
          fill
          priority
          unoptimized
          sizes="32px"
          onError={handleError}
          className="object-cover"
        />
      )}
    </span>
  );
}
