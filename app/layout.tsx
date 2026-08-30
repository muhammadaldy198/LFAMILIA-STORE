import type { Metadata } from "next";
import "./globals.css";

export const metadata: Metadata = {
  title: "LFAMILIA STORE — Top Up Game Cepat & Aman",
  description: "Top up Mobile Legends, Free Fire, dan PUBG Mobile dengan pembayaran praktis dan proses otomatis.",
  icons: {
    icon: [{ url: "/favicon.ico" }, { url: "/favicon.png", type: "image/png" }],
    shortcut: "/favicon.ico",
    apple: "/favicon.png",
  },
};

export default function RootLayout({
  children,
}: Readonly<{
  children: React.ReactNode;
}>) {
  return (
    <html lang="id">
      <body className="antialiased">{children}</body>
    </html>
  );
}
