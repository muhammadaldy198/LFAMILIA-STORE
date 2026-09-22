import { pageMetadata } from "@/lib/seo";

export const metadata = pageMetadata({
  title: "Kalkulator Zodiac",
  description: "Gunakan kalkulator Zodiac LFAMILIA STORE untuk membantu menghitung progres event.",
  path: "/tools/zodiac",
});

export default function Layout({ children }: { children: React.ReactNode }) {
  return children;
}
