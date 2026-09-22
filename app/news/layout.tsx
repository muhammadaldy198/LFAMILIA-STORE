import { pageMetadata } from "@/lib/seo";

export const metadata = pageMetadata({
  title: "Berita & Informasi",
  description: "Berita, pembaruan layanan, dan informasi terbaru dari LFAMILIA STORE.",
  path: "/news",
});

export default function Layout({ children }: { children: React.ReactNode }) {
  return children;
}
