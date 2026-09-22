import { pageMetadata } from "@/lib/seo";

export const metadata = pageMetadata({
  title: "Kebijakan Privasi",
  description: "Kebijakan privasi dan pengelolaan data pada LFAMILIA STORE.",
  path: "/privacy",
});

export default function Layout({ children }: { children: React.ReactNode }) {
  return children;
}
