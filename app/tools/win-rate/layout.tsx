import { pageMetadata } from "@/lib/seo";

export const metadata = pageMetadata({
  title: "Kalkulator Win Rate",
  description: "Hitung kebutuhan kemenangan untuk mencapai target win rate dengan kalkulator LFAMILIA STORE.",
  path: "/tools/win-rate",
});

export default function Layout({ children }: { children: React.ReactNode }) {
  return children;
}
