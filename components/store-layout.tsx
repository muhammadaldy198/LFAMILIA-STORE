import { AnnouncementBar } from "@/components/announcement-bar";
import { StoreFooter } from "@/components/store-footer";
import { StoreHeader } from "@/components/store-header";

export function StoreLayout({ children }: { children: React.ReactNode }) {
  return <div className="site-shell min-h-screen bg-[#07090f] text-white"><AnnouncementBar /><StoreHeader />{children}<StoreFooter /></div>;
}
