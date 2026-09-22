import { pageMetadata } from "@/lib/seo";

export const metadata = pageMetadata({
  title: "Leaderboard",
  description: "Lihat leaderboard pelanggan LFAMILIA STORE yang memilih untuk tampil secara publik.",
  path: "/leaderboard",
});

export default function Layout({ children }: { children: React.ReactNode }) {
  return children;
}
