import type { Metadata, Viewport } from "next";
import "./globals.css";

const SITE_URL = "https://lfamiliastore.my.id";

export const metadata: Metadata = {
  metadataBase: new URL(SITE_URL),
  title: {
    default: "LFAMILIA STORE — Top Up Game Cepat & Aman",
    template: "%s | LFAMILIA STORE",
  },
  description: "Top up game dan produk digital di LFAMILIA STORE dengan pembayaran praktis dan proses otomatis.",
  applicationName: "LFAMILIA STORE",
  alternates: { canonical: "/" },
  openGraph: {
    type: "website",
    locale: "id_ID",
    url: SITE_URL,
    siteName: "LFAMILIA STORE",
    title: "LFAMILIA STORE — Top Up Game Cepat & Aman",
    description: "Top up game dan produk digital dengan pembayaran praktis dan proses otomatis.",
    images: [{ url: "/favicon.png", width: 512, height: 512, alt: "LFAMILIA STORE" }],
  },
  twitter: {
    card: "summary",
    title: "LFAMILIA STORE — Top Up Game Cepat & Aman",
    description: "Top up game dan produk digital dengan pembayaran praktis dan proses otomatis.",
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

const structuredData = {
  "@context": "https://schema.org",
  "@type": "WebSite",
  name: "LFAMILIA STORE",
  url: SITE_URL,
  inLanguage: "id-ID",
  publisher: {
    "@type": "Organization",
    name: "LFAMILIA STORE",
    url: SITE_URL,
    logo: `${SITE_URL}/favicon.png`,
  },
};

export default function RootLayout({ children }: Readonly<{ children: React.ReactNode }>) {
  return (
    <html lang="id">
      <body className="antialiased">
        {children}
        <script type="application/ld+json" dangerouslySetInnerHTML={{ __html: JSON.stringify(structuredData).replace(/</g, "\\u003c") }} />
      </body>
    </html>
  );
}
