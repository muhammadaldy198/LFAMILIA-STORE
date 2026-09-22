import type { Metadata } from "next";

export const SITE_NAME = "LFAMILIA STORE";
export const SITE_DESCRIPTION = "Top up game, voucher, dan produk digital dengan proses praktis, pembayaran aman, dan status transaksi yang mudah dipantau.";

function configuredSiteUrl() {
  const configured =
    (typeof process !== "undefined" && (process.env.NEXT_PUBLIC_SITE_URL || process.env.PUBLIC_BASE_URL)) ||
    "https://lfamiliastore.my.id";
  try {
    return new URL(configured).origin;
  } catch {
    return "https://lfamiliastore.my.id";
  }
}

export const SITE_ORIGIN = configuredSiteUrl();
export const SITE_URL = new URL(SITE_ORIGIN);

export function absoluteUrl(path = "/") {
  return new URL(path, SITE_URL).toString();
}

export function pageMetadata(input: {
  title: string;
  description: string;
  path: string;
  image?: string;
  type?: "website" | "article";
}): Metadata {
  const url = absoluteUrl(input.path);
  const image = absoluteUrl(input.image || "/brand/lfamilia-pixel-hero.webp");
  return {
    title: input.title,
    description: input.description,
    alternates: { canonical: url },
    openGraph: {
      type: input.type || "website",
      locale: "id_ID",
      siteName: SITE_NAME,
      title: input.title,
      description: input.description,
      url,
      images: [{ url: image, alt: input.title }],
    },
    twitter: {
      card: "summary_large_image",
      title: input.title,
      description: input.description,
      images: [image],
    },
  };
}

export function noIndexMetadata(title: string): Metadata {
  return {
    title,
    robots: {
      index: false,
      follow: false,
      googleBot: { index: false, follow: false },
    },
  };
}

export const storefrontJsonLd = {
  "@context": "https://schema.org",
  "@graph": [
    {
      "@type": "WebSite",
      "@id": `${SITE_ORIGIN}/#website`,
      url: SITE_ORIGIN,
      name: SITE_NAME,
      inLanguage: "id-ID",
      description: SITE_DESCRIPTION,
    },
    {
      "@type": "OnlineStore",
      "@id": `${SITE_ORIGIN}/#store`,
      url: SITE_ORIGIN,
      name: SITE_NAME,
      description: SITE_DESCRIPTION,
      logo: absoluteUrl("/brand/lfamilia-pixel-logo.webp"),
      image: absoluteUrl("/brand/lfamilia-pixel-hero.webp"),
      currenciesAccepted: "IDR",
      areaServed: "ID",
    },
  ],
};
