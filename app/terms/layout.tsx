import { pageMetadata } from "@/lib/seo";

export const metadata = pageMetadata({
  title: "Syarat & Ketentuan",
  description: "Syarat dan ketentuan penggunaan layanan LFAMILIA STORE.",
  path: "/terms",
});

export default function Layout({ children }: { children: React.ReactNode }) {
  return children;
}
