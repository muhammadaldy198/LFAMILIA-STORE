"use client";

import Link from "next/link";
import { FormEvent, useState } from "react";
import { LoaderCircle, Mail, ShieldCheck } from "lucide-react";
import { TurnstileWidget } from "@/components/turnstile-widget";

export default function ForgotPasswordPage() {
  const [email, setEmail] = useState("");
  const [token, setToken] = useState("");
  const [resetKey, setResetKey] = useState(0);
  const [message, setMessage] = useState("");
  const [error, setError] = useState("");
  const [busy, setBusy] = useState(false);

  async function submit(event: FormEvent) {
    event.preventDefault();
    setBusy(true); setError(""); setMessage("");
    try {
      const response = await fetch("/api/auth/forgot-password", {
        method: "POST", headers: { "content-type": "application/json" },
        body: JSON.stringify({ email, turnstileToken: token }),
      });
      const payload = await response.json().catch(() => ({})) as { message?: string; error?: string };
      if (!response.ok) throw new Error(payload.error || "Permintaan reset password gagal.");
      setMessage(payload.message || "Jika email terdaftar, link reset password akan dikirim.");
    } catch (reason) {
      setError(reason instanceof Error ? reason.message : "Permintaan reset password gagal.");
      setToken(""); setResetKey((value) => value + 1);
    } finally { setBusy(false); }
  }

  return <main className="grid min-h-dvh place-items-center bg-[#070910] px-4 py-10 text-white">
    <section className="w-full max-w-md rounded-3xl border border-white/10 bg-[#0d1019] p-6 shadow-2xl sm:p-8">
      <div className="grid size-11 place-items-center rounded-xl bg-[#b9ff35]/10 text-[#cfff72]"><Mail className="size-5" /></div>
      <h1 className="mt-4 text-2xl font-black">Lupa password?</h1>
      <p className="mt-2 text-sm leading-6 text-white/45">Masukkan email akun LFAMILIA. Kami akan mengirim link untuk membuat password baru.</p>
      <form onSubmit={submit} className="mt-6 space-y-4">
        <label className="block"><span className="mb-1.5 block text-xs font-semibold text-white/55">Email</span><input required type="email" autoComplete="email" value={email} onChange={(e) => setEmail(e.target.value)} className="h-12 w-full rounded-xl border border-white/10 bg-[#10131b] px-4 text-base outline-none focus:border-[#b9ff35]/45" placeholder="nama@email.com" /></label>
        <TurnstileWidget key={resetKey} onToken={setToken} />
        {message && <p className="rounded-xl border border-[#b9ff35]/20 bg-[#b9ff35]/5 p-3 text-xs leading-5 text-[#dfff9d]">{message}</p>}
        {error && <p className="rounded-xl border border-red-400/20 bg-red-400/5 p-3 text-xs leading-5 text-red-200">{error}</p>}
        <button disabled={busy} className="flex h-12 w-full items-center justify-center rounded-xl bg-[#b9ff35] font-black text-[#091006] disabled:opacity-60">{busy && <LoaderCircle className="mr-2 size-4 animate-spin" />}Kirim link reset</button>
      </form>
      <p className="mt-5 flex items-start gap-2 text-[10px] leading-5 text-white/30"><ShieldCheck className="mt-0.5 size-3.5 shrink-0 text-[#b9ff35]" />Demi keamanan, kami tidak akan memberi tahu apakah email terdaftar atau tidak.</p>
      <Link href="/account" className="mt-5 block text-center text-xs font-bold text-[#cfff72] hover:underline">Kembali ke halaman masuk</Link>
    </section>
  </main>;
}
