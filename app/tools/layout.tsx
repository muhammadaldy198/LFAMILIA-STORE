import { pageMetadata } from "@/lib/seo";

export const metadata = pageMetadata({
  title: "Kalkulator Game",
  description: "Gunakan kalkulator dan alat bantu game LFAMILIA STORE seperti Win Rate, Magic Wheel, dan Zodiac.",
  path: "/tools",
});

export default function Layout({ children }: { children: React.ReactNode }) {
  return children;
}
