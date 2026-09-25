import type { Metadata, Viewport } from "next";
import "./globals.css";

export const metadata: Metadata = {
  metadataBase: new URL("https://lfamiliastore.my.id"),
  title: {
    default: "LFAMILIA STORE — Top Up Game Cepat & Aman",
    template: "%s | LFAMILIA STORE",
  },
  description: "Top up Mobile Legends, Free Fire, PUBG Mobile, dan produk digital di LFAMILIA STORE dengan pembayaran praktis dan proses yang jelas.",
  applicationName: "LFAMILIA STORE",
  openGraph: {
    type: "website",
    locale: "id_ID",
    url: "https://lfamiliastore.my.id",
    siteName: "LFAMILIA STORE",
    title: "LFAMILIA STORE — Top Up Game Cepat & Aman",
    description: "Top up game dan produk digital dengan pembayaran praktis dan proses yang jelas.",
    images: [{ url: "/brand/lfamilia-logo-transparent-v2.png", width: 320, height: 320, alt: "LFAMILIA STORE" }],
  },
  twitter: {
    card: "summary",
    title: "LFAMILIA STORE — Top Up Game Cepat & Aman",
    description: "Top up game dan produk digital dengan pembayaran praktis dan proses yang jelas.",
    images: ["/brand/lfamilia-logo-transparent-v2.png"],
  },
  robots: { index: true, follow: true },
  icons: {
    icon: [{ url: "/brand/lfamilia-logo-transparent-v2.png", type: "image/png" }],
    shortcut: "/brand/lfamilia-logo-transparent-v2.png",
    apple: "/brand/lfamilia-logo-transparent-v2.png",
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
  return (
    <html lang="id">
      <body className="antialiased">
        <script
          type="application/ld+json"
          dangerouslySetInnerHTML={{ __html: JSON.stringify({
            "@context": "https://schema.org",
            "@type": "Organization",
            name: "LFAMILIA STORE",
            url: "https://lfamiliastore.my.id",
            logo: "https://lfamiliastore.my.id/brand/lfamilia-logo-transparent-v2.png",
          }) }}
        />
        <script
          type="application/ld+json"
          dangerouslySetInnerHTML={{ __html: JSON.stringify({
            "@context": "https://schema.org",
            "@type": "WebSite",
            name: "LFAMILIA STORE",
            url: "https://lfamiliastore.my.id",
            inLanguage: "id-ID",
          }) }}
        />
        {children}
      </body>
    </html>
  );
}
