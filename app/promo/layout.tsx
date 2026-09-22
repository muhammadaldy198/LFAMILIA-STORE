import { pageMetadata } from "@/lib/seo";

export const metadata = pageMetadata({
  title: "Promo",
  description: "Lihat promo dan penawaran produk digital yang sedang tersedia di LFAMILIA STORE.",
  path: "/promo",
});

export default function Layout({ children }: { children: React.ReactNode }) {
  return children;
}
