import { DemoBanner } from "@/components/demo-banner";
import { MobileBottomNav } from "@/components/mobile-bottom-nav";
import { StoreFooter } from "@/components/store-footer";
import { StoreHeader } from "@/components/store-header";

export function StoreLayout({ children }: { children: React.ReactNode }) {
  return <div className="site-shell min-h-screen bg-[#07090f] pb-20 text-white md:pb-0"><DemoBanner /><StoreHeader />{children}<StoreFooter /><MobileBottomNav /></div>;
}
