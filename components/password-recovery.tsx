"use client";

import Link from "next/link";
import { FormEvent, useState } from "react";
import { Eye, EyeOff, LoaderCircle, LockKeyhole, Mail, ShieldCheck } from "lucide-react";
import { Button } from "@/components/ui/button";
import { TurnstileWidget } from "@/components/turnstile-widget";

export function PasswordRecovery({ mode }: { mode: "request" | "reset" }) {
  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");
  const [confirmation, setConfirmation] = useState("");
  const [showPassword, setShowPassword] = useState(false);
  const [turnstileToken, setTurnstileToken] = useState("");
  const [turnstileReset, setTurnstileReset] = useState(0);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState("");
  const [message, setMessage] = useState("");

  async function submit(event: FormEvent) {
    event.preventDefault();
    setError("");
    setMessage("");

    if (mode === "reset" && password !== confirmation) {
      setError("Konfirmasi password tidak sama.");
      return;
    }
    const resetToken = mode === "reset"
      ? new URLSearchParams(window.location.search).get("token")?.trim() ?? ""
      : "";
    if (mode === "reset" && !resetToken) {
      setError("Link reset password tidak valid atau sudah kedaluwarsa.");
      return;
    }

    setSaving(true);
    try {
      const response = await fetch(
        mode === "request" ? "/api/auth/forgot-password" : "/api/auth/reset-password",
        {
          method: "POST",
          headers: { "content-type": "application/json" },
          body: JSON.stringify(
            mode === "request"
              ? { email, turnstileToken }
              : { token, password, turnstileToken },
          ),
        },
      );
      const payload = await response.json().catch(() => ({})) as { error?: string; message?: string };
      if (!response.ok) throw new Error(payload.error || "Permintaan belum dapat diproses.");
      setMessage(payload.message || (mode === "request"
        ? "Jika email tersebut terdaftar, link reset password telah dikirim."
        : "Password berhasil diperbarui. Silakan masuk kembali."));
      if (mode === "reset") {
        setPassword("");
        setConfirmation("");
      }
    } catch (reason) {
      setError(reason instanceof Error ? reason.message : "Permintaan belum dapat diproses.");
      setTurnstileToken("");
      setTurnstileReset((value) => value + 1);
    } finally {
      setSaving(false);
    }
  }

  return (
    <section className="mx-auto w-full max-w-[520px] rounded-[24px] border border-white/[0.09] bg-[#090c14]/95 p-5 shadow-[0_24px_80px_rgba(0,0,0,0.36)] sm:p-7">
      <div className="flex items-start gap-3">
        <span className="grid size-10 shrink-0 place-items-center rounded-xl bg-[#b9ff35]/10 text-[#cfff72]">
          {mode === "request" ? <Mail className="size-5" /> : <LockKeyhole className="size-5" />}
        </span>
        <div>
          <h1 className="text-[24px] font-black leading-tight text-white sm:text-[28px]">
            {mode === "request" ? "Lupa password" : "Buat password baru"}
          </h1>
          <p className="mt-1.5 text-[12px] leading-5 text-white/45 sm:text-[13px]">
            {mode === "request"
              ? "Masukkan email akun LFAMILIA. Kami akan mengirim link reset yang berlaku 15 menit."
              : "Gunakan password baru minimal 8 karakter. Semua sesi akun lama akan dikeluarkan."}
          </p>
        </div>
      </div>

      <form onSubmit={submit} className="mt-6 space-y-4">
        {mode === "request" ? (
          <Field label="Email" icon={<Mail className="size-[18px]" />}>
            <input required type="email" autoComplete="email" value={email} onChange={(event) => setEmail(event.target.value)} placeholder="Masukkan alamat email Anda" className="h-12 w-full bg-transparent pl-11 pr-4 text-sm text-white outline-none placeholder:text-white/24" />
          </Field>
        ) : (
          <>
            <Field label="Password baru" icon={<LockKeyhole className="size-[18px]" />}>
              <input required minLength={8} maxLength={72} type={showPassword ? "text" : "password"} autoComplete="new-password" value={password} onChange={(event) => setPassword(event.target.value)} placeholder="Minimal 8 karakter" className="h-12 w-full bg-transparent pl-11 pr-12 text-sm text-white outline-none placeholder:text-white/24" />
              <button type="button" onClick={() => setShowPassword((value) => !value)} aria-label={showPassword ? "Sembunyikan password" : "Tampilkan password"} className="absolute bottom-0 right-0 grid h-12 w-12 place-items-center text-white/40 transition hover:text-white/75">
                {showPassword ? <EyeOff className="size-[17px]" /> : <Eye className="size-[17px]" />}
              </button>
            </Field>
            <Field label="Konfirmasi password" icon={<LockKeyhole className="size-[18px]" />}>
              <input required minLength={8} maxLength={72} type={showPassword ? "text" : "password"} autoComplete="new-password" value={confirmation} onChange={(event) => setConfirmation(event.target.value)} placeholder="Ulangi password baru" className="h-12 w-full bg-transparent pl-11 pr-4 text-sm text-white outline-none placeholder:text-white/24" />
            </Field>
          </>
        )}

        {error && <p className="rounded-xl border border-red-400/20 bg-red-400/[0.06] px-3 py-2.5 text-[11px] leading-5 text-red-200">{error}</p>}
        {message && <p className="rounded-xl border border-[#b9ff35]/20 bg-[#b9ff35]/[0.06] px-3 py-2.5 text-[11px] leading-5 text-[#d8ff8d]">{message}</p>}

        <TurnstileWidget key={turnstileReset} onToken={setTurnstileToken} />

        <Button disabled={saving || Boolean(message && mode === "reset")} className="h-12 w-full rounded-xl bg-[#b9ff35] text-sm font-black text-[#091006] hover:bg-[#c7ff58]">
          {saving && <LoaderCircle className="mr-2 size-4 animate-spin" />}
          {mode === "request" ? "Kirim link reset" : "Simpan password baru"}
        </Button>
      </form>

      <div className="mt-5 text-center text-[11px] text-white/45">
        <Link href="/login" className="font-black text-[#cfff72] hover:underline">
          Kembali ke halaman masuk
        </Link>
      </div>

      <p className="mt-5 flex items-start justify-center gap-2 text-[10px] leading-5 text-white/28">
        <ShieldCheck className="mt-0.5 size-3.5 shrink-0 text-[#b9ff35]" />
        Link reset hanya sekali pakai. LFAMILIA tidak pernah meminta password melalui chat.
      </p>
    </section>
  );
}

function Field({ label, icon, children }: { label: string; icon: React.ReactNode; children: React.ReactNode }) {
  return (
    <label className="block">
      <span className="mb-1.5 block text-[11px] font-semibold text-white/55">{label}</span>
      <span className="relative block overflow-hidden rounded-xl border border-white/[0.09] bg-[#10131b] transition focus-within:border-[#b9ff35]/45 focus-within:ring-2 focus-within:ring-[#b9ff35]/10">
        <span className="pointer-events-none absolute bottom-0 left-0 grid h-12 w-11 place-items-center text-white/36">{icon}</span>
        {children}
      </span>
    </label>
  );
}
