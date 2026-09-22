import { pageMetadata } from "@/lib/seo";

export const metadata = pageMetadata({
  title: "Hubungi Kami",
  description: "Hubungi tim bantuan LFAMILIA STORE untuk kendala transaksi dan pertanyaan layanan.",
  path: "/contact",
});

export default function Layout({ children }: { children: React.ReactNode }) {
  return children;
}
