"use client";

import { FormEvent, useEffect, useState } from "react";
import { useRouter } from "next/navigation";
import { CheckCircle2, Eye, EyeOff, LoaderCircle, ShieldCheck } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { StoreLayout } from "@/components/store-layout";

export default function OwnerSetupPage() {
  const router = useRouter();
  const [checking, setChecking] = useState(true);
  const [configured, setConfigured] = useState(false);
  const [username, setUsername] = useState("");
  const [name, setName] = useState("Pemilik LFAMILIA");
  const [password, setPassword] = useState("");
  const [confirmation, setConfirmation] = useState("");
  const [showPassword, setShowPassword] = useState(false);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState("");

  useEffect(() => {
    void fetch("/admin/setup/credential", { cache: "no-store", credentials: "same-origin" }).then(async (response) => {
      const data = await readSetupResponse(response);
      if (!response.ok) throw new Error(data.error);
      setConfigured(Boolean(data.configured));
      if (data.username) setUsername(data.username);
      if (data.name) setName(data.name);
    }).catch((reason) => setError(reason instanceof Error ? reason.message : "Status setup gagal diperiksa.")).finally(() => setChecking(false));
  }, []);

  async function save(event: FormEvent) {
    event.preventDefault();
    if (password !== confirmation) { setError("Konfirmasi password tidak sama."); return; }
    setSaving(true); setError("");
    try {
      const response = await fetch("/admin/setup/credential", {
        method: "POST",
        credentials: "same-origin",
        headers: { "content-type": "application/json" },
        body: JSON.stringify({ username, name, password }),
      });
      const data = await readSetupResponse(response);
      if (!response.ok) throw new Error(data.error || "Setup gagal.");
      router.push("/panel");
    } catch (reason) {
      setError(reason instanceof Error ? reason.message : "Setup gagal.");
    } finally {
      setSaving(false);
    }
  }

  return (
    <StoreLayout>
      <main className="mx-auto grid min-h-[72vh] max-w-xl place-items-center px-4 py-12 sm:px-6">
        <form onSubmit={save} className="panel w-full p-6 sm:p-9">
          <span className="grid size-12 place-items-center rounded-2xl border border-[#b9ff35]/20 bg-[#b9ff35]/10 text-[#b9ff35]">{configured ? <CheckCircle2 className="size-6" /> : <ShieldCheck className="size-6" />}</span>
          <p className="eyebrow mt-6">Setup aman Pemilik</p>
          <h1 className="text-2xl font-black tracking-[-0.03em]">{configured ? "Pulihkan akun Pemilik" : "Buat akun Pemilik"}</h1>
          <p className="mt-3 text-xs leading-6 text-white/40">Halaman ini hanya dapat dibuka melalui email Pemilik di Cloudflare Access. Buat ID dan password yang akan digunakan pada panel baru.</p>
          {checking && <p className="mt-5 flex items-center text-xs text-white/35"><LoaderCircle className="mr-2 size-4 animate-spin" />Memeriksa akses…</p>}
          {error && <div role="alert" className="mt-5 rounded-xl border border-red-400/20 bg-red-400/[0.06] p-3 text-xs text-red-200">{error}</div>}
          <div className="mt-6 grid gap-4">
            <label><span className="field-label">Nama Pemilik</span><Input required value={name} onChange={(event) => setName(event.target.value)} className="h-12 rounded-xl border-white/10 bg-white/[0.035] text-white" /></label>
            <label><span className="field-label">ID admin</span><Input required minLength={3} maxLength={32} autoComplete="username" value={username} onChange={(event) => setUsername(event.target.value.toLowerCase().replace(/[^a-z0-9._-]/g, ""))} placeholder="contoh: aldayyy" className="h-12 rounded-xl border-white/10 bg-white/[0.035] text-white placeholder:text-white/22" /></label>
            <label><span className="field-label">Password baru</span><div className="relative"><Input required minLength={10} maxLength={72} autoComplete="new-password" type={showPassword ? "text" : "password"} value={password} onChange={(event) => setPassword(event.target.value)} placeholder="Minimal 10 karakter" className="h-12 rounded-xl border-white/10 bg-white/[0.035] pr-12 text-white placeholder:text-white/22" /><button type="button" onClick={() => setShowPassword((value) => !value)} aria-label={showPassword ? "Sembunyikan password" : "Tampilkan password"} className="absolute inset-y-0 right-0 grid w-12 place-items-center text-white/35 hover:text-white">{showPassword ? <EyeOff className="size-4" /> : <Eye className="size-4" />}</button></div></label>
            <label><span className="field-label">Ulangi password</span><Input required minLength={10} maxLength={72} autoComplete="new-password" type={showPassword ? "text" : "password"} value={confirmation} onChange={(event) => setConfirmation(event.target.value)} placeholder="Ketik ulang password" className="h-12 rounded-xl border-white/10 bg-white/[0.035] text-white placeholder:text-white/22" /></label>
          </div>
          <Button disabled={checking || saving || Boolean(error && checking)} className="mt-6 h-12 w-full rounded-xl bg-[#b9ff35] font-black text-[#091006] hover:bg-[#d0ff75]">{saving && <LoaderCircle className="mr-2 size-4 animate-spin" />}{configured ? "Simpan akses baru" : "Aktifkan akun Pemilik"}</Button>
          <p className="mt-4 text-[10px] leading-5 text-white/28">Simpan password di tempat aman. Jangan pernah mengirimkannya melalui chat atau formulir pelanggan.</p>
        </form>
      </main>
    </StoreLayout>
  );
}

async function readSetupResponse(response: Response) {
  const body = await response.text();
  if (!body) {
    throw new Error("Cloudflare tidak mengirim respons setup. Muat ulang halaman lalu coba lagi.");
  }
  try {
    return JSON.parse(body) as { error?: string; configured?: boolean; username?: string; name?: string };
  } catch {
    throw new Error(response.redirected
      ? "Sesi Cloudflare Access perlu diperbarui. Muat ulang halaman dan masuk kembali."
      : "Respons setup tidak valid. Muat ulang halaman lalu coba lagi.");
  }
}
