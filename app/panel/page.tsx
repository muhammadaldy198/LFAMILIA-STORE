"use client";

import { useEffect, useState } from "react";
import Link from "next/link";
import { LoaderCircle, ShieldCheck, UserCog } from "lucide-react";
import { StoreLayout } from "@/components/store-layout";

export default function PanelRouterPage() {
  const [checking, setChecking] = useState(true);

  useEffect(() => {
    let active = true;
    void fetch("/api/panel/session", { cache: "no-store" })
      .then(async (response) => {
        if (!response.ok) return null;
        const data = await response.json() as { session?: { role?: "owner" | "staff" } };
        return data.session ?? null;
      })
      .then((session) => {
        if (!active || !session?.role) return;
        window.location.replace(session.role === "owner" ? "/admin/panel" : "/staff/panel");
      })
      .catch(() => undefined)
      .finally(() => {
        if (active) setChecking(false);
      });
    return () => { active = false; };
  }, []);

  return (
    <StoreLayout>
      <main className="mx-auto grid min-h-[72vh] max-w-4xl place-items-center px-4 py-12 sm:px-6">
        <div className="w-full">
          <div className="mb-6 text-center">
            <p className="eyebrow">LFAMILIA STORE</p>
            <h1 className="text-2xl font-black tracking-[-0.03em] sm:text-3xl">Pilih panel</h1>
            <p className="mt-2 text-xs text-white/35">Admin dan Staff memakai panel serta halaman login yang berbeda.</p>
          </div>
          {checking && <div className="mb-4 flex items-center justify-center text-[10px] text-white/30"><LoaderCircle className="mr-2 size-3.5 animate-spin" />Memeriksa sesi…</div>}
          <div className="grid gap-3 sm:grid-cols-2">
            <Link href="/admin/panel/login" className="rounded-2xl border border-[#b9ff35]/15 bg-[#b9ff35]/[0.04] p-5 transition hover:bg-[#b9ff35]/[0.08]">
              <span className="grid size-10 place-items-center rounded-xl bg-[#b9ff35]/10 text-[#d8ff8d]"><ShieldCheck className="size-5" /></span>
              <h2 className="mt-4 font-black">Panel Admin</h2>
              <p className="mt-2 text-[10px] leading-5 text-white/38">Akses Pemilik untuk keuangan, provider, integrasi, katalog, serta pembuatan ID dan password Staff.</p>
            </Link>
            <Link href="/staff/panel/login" className="rounded-2xl border border-blue-300/15 bg-blue-300/[0.04] p-5 transition hover:bg-blue-300/[0.08]">
              <span className="grid size-10 place-items-center rounded-xl bg-blue-300/10 text-blue-200"><UserCog className="size-5" /></span>
              <h2 className="mt-4 font-black">Panel Staff</h2>
              <p className="mt-2 text-[10px] leading-5 text-white/38">Staff masuk langsung memakai ID dan password yang dibuat Admin, tanpa login Cloudflare.</p>
            </Link>
          </div>
        </div>
      </main>
    </StoreLayout>
  );
}
