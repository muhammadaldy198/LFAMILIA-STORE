"use client";

import Link from "next/link";
import { FormEvent, useState } from "react";
import { Eye, EyeOff, LoaderCircle, LockKeyhole } from "lucide-react";
import { useSearchParams } from "next/navigation";

export default function ResetPasswordPage() {
  const params = useSearchParams();
  const token = params.get("token") || "";
  const [password, setPassword] = useState("");
  const [confirmation, setConfirmation] = useState("");
  const [show, setShow] = useState(false);
  const [busy, setBusy] = useState(false);
  const [done, setDone] = useState(false);
  const [error, setError] = useState("");

  async function submit(event: FormEvent) {
    event.preventDefault(); setError("");
    if (!token) return setError("Link reset password tidak valid.");
    if (password !== confirmation) return setError("Konfirmasi password tidak sama.");
    setBusy(true);
    try {
      const response = await fetch("/api/auth/reset-password", {
        method: "POST", headers: { "content-type": "application/json" },
        body: JSON.stringify({ token, password }),
      });
      const payload = await response.json().catch(() => ({})) as { error?: string };
      if (!response.ok) throw new Error(payload.error || "Reset password gagal.");
      setDone(true); setPassword(""); setConfirmation("");
    } catch (reason) { setError(reason instanceof Error ? reason.message : "Reset password gagal."); }
    finally { setBusy(false); }
  }

  return <main className="grid min-h-dvh place-items-center bg-[#070910] px-4 py-10 text-white">
    <section className="w-full max-w-md rounded-3xl border border-white/10 bg-[#0d1019] p-6 shadow-2xl sm:p-8">
      <div className="grid size-11 place-items-center rounded-xl bg-[#b9ff35]/10 text-[#cfff72]"><LockKeyhole className="size-5" /></div>
      <h1 className="mt-4 text-2xl font-black">Buat password baru</h1>
      <p className="mt-2 text-sm leading-6 text-white/45">Gunakan minimal 8 karakter. Setelah berhasil, semua sesi login lama akan dikeluarkan.</p>
      {done ? <div className="mt-6"><p className="rounded-xl border border-[#b9ff35]/20 bg-[#b9ff35]/5 p-4 text-sm text-[#dfff9d]">Password berhasil diperbarui.</p><Link href="/account" className="mt-4 flex h-12 items-center justify-center rounded-xl bg-[#b9ff35] font-black text-[#091006]">Masuk kembali</Link></div> :
      <form onSubmit={submit} className="mt-6 space-y-4">
        {[["Password baru", password, setPassword], ["Konfirmasi password", confirmation, setConfirmation]].map(([label, value, setter]) => <label key={String(label)} className="block"><span className="mb-1.5 block text-xs font-semibold text-white/55">{String(label)}</span><span className="relative block"><input required minLength={8} maxLength={72} type={show ? "text" : "password"} autoComplete="new-password" value={String(value)} onChange={(e) => (setter as (v:string)=>void)(e.target.value)} className="h-12 w-full rounded-xl border border-white/10 bg-[#10131b] px-4 pr-12 text-base outline-none focus:border-[#b9ff35]/45" /><button type="button" onClick={() => setShow(v => !v)} aria-label={show ? "Sembunyikan password" : "Tampilkan password"} className="absolute inset-y-0 right-0 grid w-12 place-items-center text-white/40">{show ? <EyeOff className="size-4" /> : <Eye className="size-4" />}</button></span></label>)}
        {error && <p className="rounded-xl border border-red-400/20 bg-red-400/5 p-3 text-xs leading-5 text-red-200">{error}</p>}
        <button disabled={busy || !token} className="flex h-12 w-full items-center justify-center rounded-xl bg-[#b9ff35] font-black text-[#091006] disabled:opacity-50">{busy && <LoaderCircle className="mr-2 size-4 animate-spin" />}Simpan password baru</button>
      </form>}
    </section>
  </main>;
}
