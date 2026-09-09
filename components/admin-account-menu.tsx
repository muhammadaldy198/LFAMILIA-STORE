"use client";

import { useEffect, useRef, useState } from "react";
import { ChevronDown, LayoutDashboard, LogOut, Settings, ShieldCheck } from "lucide-react";

type AccountSession = {
  email: string;
  name: string;
  role: "owner" | "staff";
};

export function AdminAccountMenu({
  session,
  logoutPath,
  onNavigate,
}: {
  session: AccountSession;
  logoutPath: string;
  onNavigate: (tab: string) => void;
}) {
  const [open, setOpen] = useState(false);
  const containerRef = useRef<HTMLDivElement>(null);
  const isOwner = session.role === "owner";
  const initial = session.name.trim().charAt(0).toUpperCase() || "A";

  useEffect(() => {
    function closeOutside(event: MouseEvent) {
      if (!containerRef.current?.contains(event.target as Node)) setOpen(false);
    }
    function closeWithEscape(event: KeyboardEvent) {
      if (event.key === "Escape") setOpen(false);
    }
    document.addEventListener("mousedown", closeOutside);
    document.addEventListener("keydown", closeWithEscape);
    return () => {
      document.removeEventListener("mousedown", closeOutside);
      document.removeEventListener("keydown", closeWithEscape);
    };
  }, []);

  function navigate(tab: string) {
    setOpen(false);
    onNavigate(tab);
  }

  return (
    <div ref={containerRef} className="relative">
      <button
        type="button"
        onClick={() => setOpen((value) => !value)}
        className="flex h-9 items-center gap-2 rounded-md px-1.5 text-left transition hover:bg-[#f6f8fc] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#155eef]/30 sm:pr-2"
        aria-label="Buka menu akun"
        aria-haspopup="menu"
        aria-expanded={open}
      >
        <span className="grid size-8 shrink-0 place-items-center rounded-full bg-[#155eef] text-[10px] font-black text-white">
          {initial}
        </span>
        <span className="hidden min-w-0 leading-tight sm:block">
          <strong className="block max-w-28 truncate text-[10px] text-[#1e293b]">{session.name}</strong>
          <span className="block text-[8px] text-[#94a3b8]">{isOwner ? "Super Admin" : "Staff"}</span>
        </span>
        <ChevronDown className={`hidden size-3.5 text-[#94a3b8] transition sm:block ${open ? "rotate-180" : ""}`} />
      </button>

      {open && (
        <div
          role="menu"
          aria-label="Menu akun admin"
          className="absolute right-0 top-11 z-50 w-[min(17rem,calc(100vw-1.5rem))] overflow-hidden rounded-lg border border-[#e2e8f0] bg-white shadow-[0_18px_50px_rgba(15,23,42,0.18)]"
        >
          <div className="border-b border-[#edf0f5] px-3 py-3">
            <div className="flex items-center gap-2.5">
              <span className="grid size-9 shrink-0 place-items-center rounded-full bg-[#155eef] text-[11px] font-black text-white">{initial}</span>
              <span className="min-w-0">
                <strong className="block truncate text-[10px] text-[#172033]">{session.name}</strong>
                <span className="mt-0.5 block truncate text-[8px] text-[#7c8aa0]">{session.email}</span>
              </span>
            </div>
            <span className="mt-2 inline-flex items-center gap-1 rounded-full bg-[#edf4ff] px-2 py-1 text-[8px] font-bold text-[#155eef]">
              <ShieldCheck className="size-3" />
              {isOwner ? "Super Admin" : "Staff"}
            </span>
          </div>

          <div className="p-1.5">
            <button type="button" role="menuitem" onClick={() => navigate("overview")} className="flex h-9 w-full items-center gap-2 rounded-md px-2.5 text-[9px] font-semibold text-[#475569] hover:bg-[#f6f8fc] hover:text-[#172033]">
              <LayoutDashboard className="size-3.5" />
              Dashboard
            </button>
            {isOwner && (
              <button type="button" role="menuitem" onClick={() => navigate("settings")} className="flex h-9 w-full items-center gap-2 rounded-md px-2.5 text-[9px] font-semibold text-[#475569] hover:bg-[#f6f8fc] hover:text-[#172033]">
                <Settings className="size-3.5" />
                Pengaturan
              </button>
            )}
          </div>

          <div className="border-t border-[#edf0f5] p-1.5">
            <form action={logoutPath} method="post">
              <button type="submit" role="menuitem" className="flex h-9 w-full items-center gap-2 rounded-md px-2.5 text-[9px] font-bold text-red-600 hover:bg-red-50">
                <LogOut className="size-3.5" />
                Keluar dari panel
              </button>
            </form>
          </div>
        </div>
      )}
    </div>
  );
}
