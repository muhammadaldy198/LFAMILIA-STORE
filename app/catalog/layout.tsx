import { pageMetadata } from "@/lib/seo";

export const metadata = pageMetadata({
  title: "Katalog Top Up & Voucher",
  description: "Jelajahi katalog top up game, voucher, pulsa, dan produk digital LFAMILIA STORE.",
  path: "/catalog",
});

export default function Layout({ children }: { children: React.ReactNode }) {
  return children;
}
