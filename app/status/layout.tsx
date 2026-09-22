import { pageMetadata } from "@/lib/seo";

export const metadata = pageMetadata({
  title: "Status Layanan",
  description: "Periksa status layanan dan ketersediaan sistem LFAMILIA STORE.",
  path: "/status",
});

export default function Layout({ children }: { children: React.ReactNode }) {
  return children;
}
