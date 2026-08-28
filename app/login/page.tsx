"use client";

import { FormEvent, useState } from "react";
import Link from "next/link";
import { Eye, EyeOff, LockKeyhole, Mail, ShieldCheck, UserRound } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs";
import { StoreLayout } from "@/components/store-layout";

export default function LoginPage() {
  const [showPassword, setShowPassword] = useState(false);
  const [message, setMessage] = useState("");
  function simulate(event: FormEvent, action: string) { event.preventDefault(); setMessage(`${action} belum aktif karena sistem akun masih dalam mode desain.`); }

  return (
    <StoreLayout>
      <main className="mx-auto grid min-h-[70vh] max-w-5xl items-center gap-10 px-4 py-14 sm:px-6 lg:grid-cols-[.85fr_1.15fr] lg:px-8 lg:py-20">
        <div className="hidden lg:block"><span className="grid size-14 place-items-center rounded-2xl bg-[#b9ff35] text-lg font-black text-[#091006]">LF</span><h1 className="mt-7 text-5xl font-black leading-[.95] tracking-[-0.05em]">Satu akun untuk semua transaksi.</h1><p className="mt-5 max-w-md text-sm leading-7 text-white/42">Simpan riwayat pesanan, data tujuan favorit, dan pantau transaksi dari satu tempat.</p><div className="mt-7 space-y-3 text-xs text-white/48">{["Riwayat transaksi tersimpan", "Data tujuan favorit", "Notifikasi status pesanan"].map((item) => <p key={item} className="flex items-center gap-2"><ShieldCheck className="size-4 text-[#b9ff35]" />{item}</p>)}</div></div>
        <div className="panel p-5 sm:p-7"><div className="mb-6 text-center lg:hidden"><span className="mx-auto grid size-11 place-items-center rounded-xl bg-[#b9ff35] text-sm font-black text-[#091006]">LF</span><h1 className="mt-4 text-2xl font-black">Akun LFAMILIA</h1></div>
          <Tabs defaultValue="login">
            <TabsList className="grid h-11 w-full grid-cols-2 rounded-xl bg-white/[0.045] p-1"><TabsTrigger value="login" className="rounded-lg text-xs data-[state=active]:bg-[#b9ff35] data-[state=active]:text-[#091006]">Masuk</TabsTrigger><TabsTrigger value="register" className="rounded-lg text-xs data-[state=active]:bg-[#b9ff35] data-[state=active]:text-[#091006]">Daftar</TabsTrigger></TabsList>
            <TabsContent value="login"><form onSubmit={(event) => simulate(event, "Login")} className="mt-6 space-y-4"><div><label className="field-label" htmlFor="login-email">Email</label><div className="relative"><Mail className="absolute left-3.5 top-1/2 size-4 -translate-y-1/2 text-white/25" /><Input id="login-email" type="email" required placeholder="nama@email.com" className="h-11 rounded-xl border-white/10 bg-white/[0.035] pl-10 text-white placeholder:text-white/22" /></div></div><div><div className="flex items-center justify-between"><label className="field-label" htmlFor="login-password">Kata sandi</label><button type="button" className="mb-2 text-[10px] font-semibold text-[#cfff72]">Lupa sandi?</button></div><div className="relative"><LockKeyhole className="absolute left-3.5 top-1/2 size-4 -translate-y-1/2 text-white/25" /><Input id="login-password" type={showPassword ? "text" : "password"} required placeholder="••••••••" className="h-11 rounded-xl border-white/10 bg-white/[0.035] px-10 text-white placeholder:text-white/22" /><button type="button" onClick={() => setShowPassword(!showPassword)} className="absolute right-3.5 top-1/2 -translate-y-1/2 text-white/28" aria-label="Lihat kata sandi">{showPassword ? <EyeOff className="size-4" /> : <Eye className="size-4" />}</button></div></div><Button className="h-11 w-full rounded-xl bg-[#b9ff35] font-black text-[#091006] hover:bg-[#d0ff75]">Masuk (demo)</Button></form></TabsContent>
            <TabsContent value="register"><form onSubmit={(event) => simulate(event, "Pendaftaran")} className="mt-6 space-y-4"><div><label className="field-label" htmlFor="name">Nama</label><div className="relative"><UserRound className="absolute left-3.5 top-1/2 size-4 -translate-y-1/2 text-white/25" /><Input id="name" required placeholder="Nama lengkap" className="h-11 rounded-xl border-white/10 bg-white/[0.035] pl-10 text-white placeholder:text-white/22" /></div></div><div><label className="field-label" htmlFor="register-email">Email</label><div className="relative"><Mail className="absolute left-3.5 top-1/2 size-4 -translate-y-1/2 text-white/25" /><Input id="register-email" type="email" required placeholder="nama@email.com" className="h-11 rounded-xl border-white/10 bg-white/[0.035] pl-10 text-white placeholder:text-white/22" /></div></div><div><label className="field-label" htmlFor="register-password">Kata sandi</label><div className="relative"><LockKeyhole className="absolute left-3.5 top-1/2 size-4 -translate-y-1/2 text-white/25" /><Input id="register-password" type="password" minLength={8} required placeholder="Minimal 8 karakter" className="h-11 rounded-xl border-white/10 bg-white/[0.035] pl-10 text-white placeholder:text-white/22" /></div></div><Button className="h-11 w-full rounded-xl bg-[#b9ff35] font-black text-[#091006] hover:bg-[#d0ff75]">Buat akun (demo)</Button></form></TabsContent>
          </Tabs>
          {message && <p className="mt-5 rounded-xl border border-amber-300/15 bg-amber-300/[0.06] p-3 text-center text-[11px] leading-5 text-amber-100/70">{message}</p>}
          <p className="mt-5 text-center text-[10px] leading-5 text-white/28">Dengan melanjutkan, Anda menyetujui <Link href="/terms" className="underline">syarat</Link> dan <Link href="/privacy" className="underline">kebijakan privasi</Link>.</p>
        </div>
      </main>
    </StoreLayout>
  );
}

