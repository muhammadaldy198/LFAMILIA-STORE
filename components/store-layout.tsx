import { AnnouncementBar } from "@/components/announcement-bar";
import { StoreFooter } from "@/components/store-footer";
import { StoreHeader } from "@/components/store-header";
import { FloatingLiveSupport } from "@/components/floating-live-support";

export function StoreLayout({ children, customerTheme = true }: { children: React.ReactNode; customerTheme?: boolean }) {
  return <div className={`${customerTheme ? "customer-theme " : ""}site-shell min-h-screen bg-[#07090f] text-white`}><AnnouncementBar /><StoreHeader />{children}<StoreFooter /><FloatingLiveSupport /></div>;
}
