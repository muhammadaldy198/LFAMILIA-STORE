import { pageMetadata } from "@/lib/seo";

export const metadata = pageMetadata({
  title: "Kalkulator Magic Wheel",
  description: "Gunakan kalkulator Magic Wheel untuk memperkirakan kebutuhan putaran dan progres.",
  path: "/tools/magic-wheel",
});

export default function Layout({ children }: { children: React.ReactNode }) {
  return children;
}
