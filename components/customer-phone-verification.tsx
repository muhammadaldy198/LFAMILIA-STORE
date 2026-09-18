"use client";

import { FormEvent, useMemo, useState } from "react";
import { CheckCircle2, LoaderCircle, LogOut, MessageCircle, ShieldCheck } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import type { CustomerSession } from "@/lib/server/customer-auth";

type Challenge = {
  challengeId: string;
  phone: string;
  expiresInSeconds: number;
  resendAfterSeconds: number;
};

export function CustomerPhoneVerification({
  customer,
  onVerified,
  onLogout,
}: {
  customer: CustomerSession;
  onVerified(): Promise<void>;
  onLogout(): Promise<void>;
}) {
  const [phone, setPhone] = useState(customer.phone || "");
  const [challenge, setChallenge] = useState<Challenge | null>(null);
  const [code, setCode] = useState("");
  const [busy, setBusy] = useState(false);
  const [message, setMessage] = useState("");
  const [error, setError] = useState("");

  const destination = useMemo(() => challenge?.phone || "nomor WhatsApp kamu", [challenge]);

  async function sendOtp(event?: FormEvent) {
    event?.preventDefault();
    setBusy(true);
    setError("");
    setMessage("");
    try {
      const response = await fetch("/api/account/phone/send-otp", {
        method: "POST",
        headers: { "content-type": "application/json" },
        body: JSON.stringify({ phone }),
      });
      const payload = await response.json().catch(() => ({})) as Challenge & { error?: string };
      if (!response.ok) throw new Error(payload.error || "OTP WhatsApp gagal dikirim.");
      setChallenge(payload);
      setCode("");
      setMessage(`Kode 6 digit sudah dikirim ke ${payload.phone}.`);
    } catch (reason) {
      setError(reason instanceof Error ? reason.message : "OTP WhatsApp gagal dikirim.");
    } finally {
      setBusy(false);
    }
  }

  async function verifyOtp(event: FormEvent) {
    event.preventDefault();
    if (!challenge) return;
    setBusy(true);
    setError("");
    setMessage("");
    try {
      const response = await fetch("/api/account/phone/verify-otp", {
        method: "POST",
        headers: { "content-type": "application/json" },
        body: JSON.stringify({ challengeId: challenge.challengeId, code }),
      });
      const payload = await response.json().catch(() => ({})) as { error?: string };
      if (!response.ok) throw new Error(payload.error || "OTP tidak dapat diverifikasi.");
      setMessage("Nomor WhatsApp berhasil diverifikasi.");
      window.dispatchEvent(new Event("lfamilia:auth-changed"));
      await onVerified();
    } catch (reason) {
      setError(reason instanceof Error ? reason.message : "OTP tidak dapat diverifikasi.");
    } finally {
      setBusy(false);
    }
  }

  return (
    <section className="mx-auto w-full max-w-[560px] rounded-[24px] border border-white/[0.09] bg-[#090c14]/95 p-5 shadow-[0_24px_80px_rgba(0,0,0,0.36)] sm:p-7">
      <div className="flex items-start gap-3">
        <span className="grid size-10 shrink-0 place-items-center rounded-xl bg-[#25d366]/10 text-[#7cf4a8]">
          <MessageCircle className="size-5" />
        </span>
        <div className="min-w-0">
          <p className="text-[9px] font-black uppercase tracking-[0.2em] text-[#cfff72]">Satu langkah lagi</p>
          <h1 className="mt-1 text-[24px] font-black leading-tight text-white sm:text-[28px]">Verifikasi WhatsApp</h1>
          <p className="mt-1.5 text-[12px] leading-5 text-white/45 sm:text-[13px]">
            Amankan akun {customer.email} dan gunakan nomor terverifikasi untuk transaksi serta pemulihan akun.
          </p>
        </div>
      </div>

      {!challenge ? (
        <form onSubmit={sendOtp} className="mt-6">
          <label className="block">
            <span className="mb-1.5 block text-[11px] font-semibold text-white/55">Nomor WhatsApp</span>
            <Input
              required
              inputMode="tel"
              autoComplete="tel"
              value={phone}
              onChange={(event) => setPhone(event.target.value)}
              placeholder="081234567890"
              className="h-12 rounded-xl border-white/[0.09] bg-[#10131b] text-white"
            />
          </label>
          <p className="mt-2 text-[10px] leading-4 text-white/32">
            Kami akan mengirim kode OTP 6 digit melalui WhatsApp. Kode berlaku 5 menit.
          </p>
          {error && <p className="mt-3 rounded-xl border border-red-400/20 bg-red-400/[0.06] px-3 py-2 text-[11px] text-red-200">{error}</p>}
          <Button disabled={busy} className="mt-4 h-12 w-full rounded-xl bg-[#b9ff35] font-black text-[#091006] hover:bg-[#c7ff58]">
            {busy ? <LoaderCircle className="mr-2 size-4 animate-spin" /> : <MessageCircle className="mr-2 size-4" />}
            Kirim OTP WhatsApp
          </Button>
        </form>
      ) : (
        <form onSubmit={verifyOtp} className="mt-6">
          <div className="rounded-xl border border-[#25d366]/15 bg-[#25d366]/[0.04] p-3">
            <p className="text-[10px] text-white/38">Kode dikirim ke</p>
            <strong className="mt-1 block text-sm text-white">{destination}</strong>
          </div>
          <label className="mt-4 block">
            <span className="mb-1.5 block text-[11px] font-semibold text-white/55">Kode OTP</span>
            <Input
              required
              inputMode="numeric"
              autoComplete="one-time-code"
              maxLength={6}
              pattern="[0-9]{6}"
              value={code}
              onChange={(event) => setCode(event.target.value.replace(/\D/g, "").slice(0, 6))}
              placeholder="000000"
              className="h-12 rounded-xl border-white/[0.09] bg-[#10131b] text-center text-lg font-black tracking-[0.35em] text-white"
            />
          </label>
          {message && <p className="mt-3 flex items-center gap-2 text-[11px] text-[#b9ff35]"><CheckCircle2 className="size-4" />{message}</p>}
          {error && <p className="mt-3 rounded-xl border border-red-400/20 bg-red-400/[0.06] px-3 py-2 text-[11px] text-red-200">{error}</p>}
          <Button disabled={busy || code.length !== 6} className="mt-4 h-12 w-full rounded-xl bg-[#b9ff35] font-black text-[#091006] hover:bg-[#c7ff58]">
            {busy && <LoaderCircle className="mr-2 size-4 animate-spin" />}
            Verifikasi
          </Button>
          <div className="mt-3 flex items-center justify-between gap-3">
            <button
              type="button"
              disabled={busy}
              onClick={() => {
                setChallenge(null);
                setCode("");
                setError("");
                setMessage("");
              }}
              className="text-[11px] font-semibold text-white/50 hover:text-white"
            >
              Ganti nomor
            </button>
            <button type="button" disabled={busy} onClick={() => void sendOtp()} className="text-[11px] font-bold text-[#cfff72] hover:underline">
              Kirim ulang OTP
            </button>
          </div>
        </form>
      )}

      <div className="mt-5 flex items-center justify-between gap-3 border-t border-white/[0.07] pt-4">
        <p className="flex items-center gap-2 text-[9px] leading-4 text-white/30">
          <ShieldCheck className="size-3.5 text-[#b9ff35]" />
          OTP tidak pernah ditampilkan kepada admin.
        </p>
        <button type="button" onClick={() => void onLogout()} className="inline-flex items-center gap-1.5 text-[10px] font-bold text-white/45 hover:text-white">
          <LogOut className="size-3.5" /> Keluar
        </button>
      </div>
    </section>
  );
}
