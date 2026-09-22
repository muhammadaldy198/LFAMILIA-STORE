import { pageMetadata } from "@/lib/seo";

export const metadata = pageMetadata({
  title: "FAQ",
  description: "Jawaban untuk pertanyaan umum tentang top up, pembayaran, status transaksi, dan layanan LFAMILIA STORE.",
  path: "/faq",
});

export default function Layout({ children }: { children: React.ReactNode }) {
  return children;
}
