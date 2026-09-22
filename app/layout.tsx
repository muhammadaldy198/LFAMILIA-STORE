import type { Metadata, Viewport } from "next";
import "./globals.css";

export const metadata: Metadata = {
  metadataBase: new URL("https://lfamiliastore.my.id"),
  title: {
    default: "LFAMILIA STORE — Top Up Game Cepat & Aman",
    template: "%s | LFAMILIA STORE",
  },
  description: "Top up game dan produk digital di LFAMILIA STORE dengan pembayaran praktis dan proses yang jelas.",
  applicationName: "LFAMILIA STORE",
  openGraph: {
    type: "website",
    locale: "id_ID",
    url: "https://lfamiliastore.my.id",
    siteName: "LFAMILIA STORE",
    title: "LFAMILIA STORE — Top Up Game Cepat & Aman",
    description: "Top up game dan produk digital dengan pembayaran praktis dan proses yang jelas.",
    images: [{ url: "/favicon.png", width: 200, height: 200, alt: "LFAMILIA STORE" }],
  },
  twitter: {
    card: "summary",
    title: "LFAMILIA STORE — Top Up Game Cepat & Aman",
    description: "Top up game dan produk digital dengan pembayaran praktis dan proses yang jelas.",
    images: ["/favicon.png"],
  },
  robots: { index: true, follow: true },
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
            logo: "https://lfamiliastore.my.id/favicon.png",
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
