"use client";

import { useState } from "react";
type ArtworkProduct = { name: string; imageUrl?: string | null; initials: string; accent: string };

export function ProductArtwork({ product, compact = false }: { product: ArtworkProduct; compact?: boolean }) {
  const [failedUrl, setFailedUrl] = useState<string | null>(null);
  return (
    <div className={`relative grid size-full place-items-center overflow-hidden bg-gradient-to-br ${product.accent}`}>
      <span className="absolute inset-0 opacity-20 [background-image:linear-gradient(rgba(255,255,255,.18)_1px,transparent_1px),linear-gradient(90deg,rgba(255,255,255,.18)_1px,transparent_1px)] [background-size:8px_8px]" aria-hidden="true" />
      <span className={`relative z-0 font-mono font-black tracking-[-0.08em] text-white/90 [text-shadow:3px_3px_0_rgba(0,0,0,.38)] ${compact ? "text-xs" : "text-5xl"}`}>{product.initials}</span>
      {product.imageUrl && failedUrl !== product.imageUrl && (
        // Images are managed by the owner and may be served from any HTTPS image host.
        // eslint-disable-next-line @next/next/no-img-element
        <img key={product.imageUrl} src={product.imageUrl} alt={product.name} className="absolute inset-0 z-10 size-full object-cover" onError={() => setFailedUrl(product.imageUrl ?? null)} />
      )}
      <span className="absolute inset-0 z-20 bg-gradient-to-t from-black/38 via-transparent to-white/[0.04]" aria-hidden="true" />
    </div>
  );
}
