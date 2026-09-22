"use client";

import Link from "next/link";
import { FormEvent, useState } from "react";
import { useSearchParams } from "next/navigation";
import { Eye, EyeOff, LoaderCircle, LockKeyhole } from "lucide-react";
import { Button } from "@/components/ui/button";

export default function ResetPasswordPage() {
  const searchParams = useSearchParams();
  const token = searchParams.get("token") || "";
  const [password, setPassword] = useState("");
  const [confirmation, setConfirmation] = useState("");
  const [show, setShow] = useState(false);
  const [busy, setBusy] = useState(false);
  const [message, setMessage] = useState("");
  const [error, setError] = useState("");

  async function submit(event: FormEvent) {
    event.preventDefault();
    setError("");
    setMessage("");
    if (!token) return setError("Link reset password tidak valid.");
    if (password !== confirmation) return setError("Konfirmasi password tidak sama.");
    setBusy(true);
    try {
      const response = await fetch("/api/auth/reset-password", {
        method: "POST",
        headers: { "content-type": "application/json" },
        body: JSON.stringify({ token, password }),
      });
      const payload = await response.json().catch(() => ({})) as { error?: string; message?: string };
      if (!response.ok) throw new Error(payload.error || "Reset password gagal.");
      setMessage(payload.message || "Password berhasil diubah.");
      setPassword("");
      setConfirmation("");
    } catch (reason) {
      setError(reason instanceof Error ? reason.message : "Reset password gagal.");
    } finally {
      setBusy(false);
    }
  }

  return <main className="min-h-dvh bg-[#05070c] px-4 py-12 text-white">
    <section className="mx-auto w-full max-w-md rounded-2xl border border-white/10 bg-[#090c14] p-5 sm:p-7">
      <div className="mb-6 flex items-center gap-3"><span className="grid size-10 place-items-center rounded-xl bg-[#b9ff35]/10 text-[#cfff72]"><LockKeyhole className="size-5" /></span><div><h1 className="text-xl font-black">Reset password</h1><p className="mt-1 text-xs text-white/45">Buat password baru untuk akun LFAMILIA.</p></div></div>
      <form onSubmit={submit} className="space-y-4">
        <label className="block"><span className="mb-1.5 block text-xs text-white/55">Password baru</span><span className="relative block"><input required minLength={8} maxLength={72} type={show ? "text" : "password"} autoComplete="new-password" value={password} onChange={(e)=>setPassword(e.target.value)} className="checkout-input h-12 w-full rounded-xl border border-white/10 bg-[#10131b] px-4 pr-12 outline-none" /><button type="button" onClick={()=>setShow(v=>!v)} className="absolute right-0 top-0 grid size-12 place-items-center text-white/45" aria-label={show ? "Sembunyikan password" : "Tampilkan password"}>{show ? <EyeOff className="size-4"/> : <Eye className="size-4"/>}</button></span></label>
        <label className="block"><span className="mb-1.5 block text-xs text-white/55">Konfirmasi password</span><input required minLength={8} maxLength={72} type={show ? "text" : "password"} autoComplete="new-password" value={confirmation} onChange={(e)=>setConfirmation(e.target.value)} className="checkout-input h-12 w-full rounded-xl border border-white/10 bg-[#10131b] px-4 outline-none" /></label>
        {error && <p className="rounded-xl border border-red-400/20 bg-red-400/[0.06] px-3 py-2.5 text-xs text-red-200">{error}</p>}
        {message && <p className="rounded-xl border border-[#b9ff35]/20 bg-[#b9ff35]/[0.06] px-3 py-2.5 text-xs text-[#d9ff91]">{message}</p>}
        <Button disabled={busy || Boolean(message)} className="h-12 w-full rounded-xl bg-[#b9ff35] font-black text-[#091006] hover:bg-[#c7ff58]">{busy && <LoaderCircle className="mr-2 size-4 animate-spin"/>}Simpan password baru</Button>
      </form>
      <Link href="/account" className="mt-5 block text-center text-xs font-bold text-[#cfff72] hover:underline">Kembali ke halaman masuk</Link>
    </section>
  </main>;
}
