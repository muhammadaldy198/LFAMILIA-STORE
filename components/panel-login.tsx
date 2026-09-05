"use client";

import { FormEvent, useState } from "react";
import Link from "next/link";
import { Eye, EyeOff, LoaderCircle, LockKeyhole, ShieldCheck } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { StoreLayout } from "@/components/store-layout";

export function PanelLogin({ role }: { role: "owner" | "staff" }) {
  const owner = role === "owner";
  const [username, setUsername] = useState("");
  const [password, setPassword] = useState("");
  const [showPassword, setShowPassword] = useState(false);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState("");

  async function login(event: FormEvent) {
    event.preventDefault();
    setLoading(true);
    setError("");
    try {
      const apiBase = owner ? "/api/admin/panel" : "/api/staff";
      const response = await fetch(`${apiBase}/auth/login`, {
        method: "POST",
        credentials: "same-origin",
        cache: "no-store",
        headers: { "content-type": "application/json" },
        body: JSON.stringify({ username, password }),
      });
      const data = await response.json();
      if (!response.ok) throw new Error(data.error || (owner ? "Login Admin gagal." : "Login Staff gagal."));

      window.location.replace(owner ? "/admin/panel" : "/staff/panel");
    } catch (reason) {
      setError(reason instanceof Error ? reason.message : "Login gagal.");
    } finally {
      setLoading(false);
    }
  }

  return (
    <StoreLayout>
      <main className="mx-auto grid min-h-[72vh] max-w-6xl place-items-center px-4 py-12 sm:px-6 lg:px-8">
        <div className="grid w-full max-w-4xl overflow-hidden rounded-[28px] border border-white/[0.09] bg-[#0d1017] shadow-2xl shadow-black/30 lg:grid-cols-[.85fr_1.15fr]">
          <section className="hidden bg-[radial-gradient(circle_at_top_left,rgba(185,255,53,0.15),transparent_52%),#090c11] p-9 lg:block">
            <span className="grid size-12 place-items-center rounded-2xl border border-[#b9ff35]/20 bg-[#b9ff35]/10 text-[#b9ff35]"><ShieldCheck className="size-6" /></span>
            <p className="eyebrow mt-8">{owner ? "Panel Admin" : "Panel Staff"}</p>
            <h1 className="text-3xl font-black tracking-[-0.04em]">{owner ? "Akses penuh LFAMILIA." : "Operasional LFAMILIA."}</h1>
            <p className="mt-4 text-xs leading-6 text-white/40">
              {owner
                ? "Pemilik mengatur katalog, pembayaran, saldo, integrasi, dan akun Staff."
                : "Staff masuk cukup dengan ID dan password yang dibuat oleh Pemilik. Tidak memerlukan login Cloudflare."}
            </p>
          </section>
          <form onSubmit={login} className="p-6 sm:p-10">
            <span className="grid size-11 place-items-center rounded-2xl bg-[#b9ff35]/10 text-[#b9ff35] lg:hidden"><LockKeyhole className="size-5" /></span>
            <p className="eyebrow mt-5 lg:mt-0">{owner ? "Admin LFAMILIA" : "Staff LFAMILIA"}</p>
            <h2 className="text-2xl font-black tracking-[-0.03em]">{owner ? "Masuk sebagai Admin" : "Masuk sebagai Staff"}</h2>
            <p className="mt-2 text-xs leading-5 text-white/35">
              {owner ? "Gunakan ID Pemilik dan password." : "Gunakan ID Staff dan password yang dibuat oleh Admin."}
            </p>
            {error && <div role="alert" className="mt-5 rounded-xl border border-red-400/20 bg-red-400/[0.06] p-3 text-xs text-red-200">{error}</div>}
            <label className="mt-6 block"><span className="field-label">{owner ? "ID admin" : "ID staff"}</span><Input required autoComplete="username" value={username} onChange={(event) => setUsername(event.target.value.toLowerCase().replace(/[^a-z0-9._-]/g, ""))} placeholder={owner ? "contoh: owner" : "contoh: staff01"} className="h-12 rounded-xl border-white/10 bg-white/[0.035] text-white placeholder:text-white/22" /></label>
            <label className="mt-4 block"><span className="field-label">Password</span><div className="relative"><Input required minLength={10} maxLength={72} autoComplete="current-password" type={showPassword ? "text" : "password"} value={password} onChange={(event) => setPassword(event.target.value)} placeholder="Masukkan password" className="h-12 rounded-xl border-white/10 bg-white/[0.035] pr-12 text-white placeholder:text-white/22" /><button type="button" onClick={() => setShowPassword((value) => !value)} aria-label={showPassword ? "Sembunyikan password" : "Tampilkan password"} className="absolute inset-y-0 right-0 grid w-12 place-items-center text-white/35 hover:text-white">{showPassword ? <EyeOff className="size-4" /> : <Eye className="size-4" />}</button></div></label>
            <Button disabled={loading} className="mt-6 h-12 w-full rounded-xl bg-[#b9ff35] font-black text-[#091006] hover:bg-[#d0ff75]">{loading ? <LoaderCircle className="mr-2 size-4 animate-spin" /> : <LockKeyhole className="mr-2 size-4" />}Masuk</Button>
            <div className={`mt-5 flex items-center gap-3 text-[10px] leading-5 text-white/28 ${owner ? "justify-between" : "justify-end"}`}>
              {owner && <Link href="/panel" className="text-[#b9ff35] hover:text-white">Pilih panel lain</Link>}
              <Link href="/" className="hover:text-white">Kembali ke toko</Link>
            </div>
          </form>
        </div>
      </main>
    </StoreLayout>
  );
}
