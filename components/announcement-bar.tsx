"use client";

import { Megaphone } from "lucide-react";
import { useStorefront } from "@/hooks/use-storefront";

export function AnnouncementBar() {
  const { settings } = useStorefront();
  if (!settings.announcement) return null;
  return <div className="border-b border-[#b9ff35]/20 bg-[#b9ff35]/[0.07] px-4 py-2 text-center text-[11px] font-medium leading-5 text-[#dcffa0] sm:text-xs"><span className="inline-flex items-center gap-2"><Megaphone className="size-3.5" />{settings.announcement}</span></div>;
}
