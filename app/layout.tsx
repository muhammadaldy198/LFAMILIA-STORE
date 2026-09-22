import type { Metadata, Viewport } from "next";
import { SITE_DESCRIPTION, SITE_NAME, SITE_URL, absoluteUrl, storefrontJsonLd } from "@/lib/seo";
import "./globals.css";

export const metadata: Metadata = {
  metadataBase: SITE_URL,
  applicationName: SITE_NAME,
  title: {
    default: "LFAMILIA STORE — Top Up Game Cepat & Aman",
    template: "%s | LFAMILIA STORE",
  },
  description: SITE_DESCRIPTION,
  keywords: ["top up game", "voucher game", "Mobile Legends", "Free Fire", "PUBG Mobile", "LFAMILIA STORE"],
  openGraph: {
    type: "website",
    locale: "id_ID",
    siteName: SITE_NAME,
    title: "LFAMILIA STORE — Top Up Game Cepat & Aman",
    description: SITE_DESCRIPTION,
    url: SITE_URL,
    images: [{ url: absoluteUrl("/brand/lfamilia-pixel-hero.webp"), alt: "LFAMILIA STORE" }],
  },
  twitter: {
    card: "summary_large_image",
    title: "LFAMILIA STORE — Top Up Game Cepat & Aman",
    description: SITE_DESCRIPTION,
    images: [absoluteUrl("/brand/lfamilia-pixel-hero.webp")],
  },
  icons: {
    icon: [{ url: "/favicon.ico" }, { url: "/favicon.png", type: "image/png" }],
    shortcut: "/favicon.ico",
    apple: "/favicon.png",
  },
};

export const viewport: Viewport = {
  width: "device-width",
  initialScale: 1,
  maximumScale: 5,
  viewportFit: "cover",
};

export default function RootLayout({
  children,
}: Readonly<{
  children: React.ReactNode;
}>) {
  const jsonLd = JSON.stringify(storefrontJsonLd).replaceAll("<", "\\u003c");
  return (
    <html lang="id">
      <body className="antialiased">
        {children}
        <script type="application/ld+json" dangerouslySetInnerHTML={{ __html: jsonLd }} />
      </body>
    </html>
  );
}
