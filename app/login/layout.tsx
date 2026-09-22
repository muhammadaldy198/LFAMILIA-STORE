import { noIndexMetadata } from "@/lib/seo";

export const metadata = noIndexMetadata("Masuk");

export default function Layout({ children }: { children: React.ReactNode }) {
  return children;
}
