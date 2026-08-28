import { DemoBanner } from "@/components/demo-banner";
import { StoreFooter } from "@/components/store-footer";
import { StoreHeader } from "@/components/store-header";

export function StoreLayout({ children }: { children: React.ReactNode }) {
  return <div className="site-shell min-h-screen bg-[#07090f] text-white"><DemoBanner /><StoreHeader />{children}<StoreFooter /></div>;
}

