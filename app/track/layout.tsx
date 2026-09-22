import { noIndexMetadata } from "@/lib/seo";

export const metadata = noIndexMetadata("Cek Transaksi");

export default function Layout({ children }: { children: React.ReactNode }) {
  return children;
}
