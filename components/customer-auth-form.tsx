"use client";

import Link from "next/link";
import { FormEvent, useEffect, useRef, useState } from "react";
import { Eye, EyeOff, LoaderCircle, LockKeyhole, LogIn, Mail, Phone, ShieldCheck, UserPlus, UserRound } from "lucide-react";
import { Button } from "@/components/ui/button";
import { TurnstileWidget } from "@/components/turnstile-widget";

type AuthMode = "login" | "register";
type GoogleCredentialResponse = { credential?: string };

declare global {
  interface Window {
    google?: {
      accounts: {
        id: {
          initialize(options: {
            client_id: string;
            callback(response: GoogleCredentialResponse): void;
            auto_select?: boolean;
            cancel_on_tap_outside?: boolean;
          }): void;
          renderButton(
            parent: HTMLElement,
            options: {
              type?: "standard" | "icon";
              theme?: "outline" | "filled_blue" | "filled_black";
              size?: "large" | "medium" | "small";
              text?: "signin_with" | "signup_with" | "continue_with" | "signin";
              shape?: "rectangular" | "pill" | "circle" | "square";
              logo_alignment?: "left" | "center";
              width?: number;
            },
          ): void;
        };
      };
    };
  }
}

export function CustomerAuthForm({
  mode,
  setMode,
  error,
  setError,
  onSuccess,
}: {
  mode: AuthMode;
  setMode(value: AuthMode): void;
  error: string;
  setError(value: string): void;
  onSuccess(): Promise<void>;
}) {
  const [name, setName] = useState("");
  const [phone, setPhone] = useState("");
  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");
  const [confirmation, setConfirmation] = useState("");
  const [agreed, setAgreed] = useState(false);
  const [showPassword, setShowPassword] = useState(false);
  const [showConfirmation, setShowConfirmation] = useState(false);
  const [turnstileToken, setTurnstileToken] = useState("");
  const [turnstileReset, setTurnstileReset] = useState(0);
  const [saving, setSaving] = useState(false);
  const [googleClientId, setGoogleClientId] = useState("");

  useEffect(() => {
    let cancelled = false;
    void fetch("/api/auth/google/status", { cache: "no-store" })
      .then((response) => response.ok ? response.json() : Promise.resolve({ enabled: false, clientId: null }))
      .then((payload: { enabled?: boolean; clientId?: string | null }) => {
        if (!cancelled) setGoogleClientId(payload.enabled && payload.clientId ? payload.clientId : "");
      })
      .catch(() => {
        if (!cancelled) setGoogleClientId("");
      });
    return () => { cancelled = true; };
  }, []);

  function changeMode(next: AuthMode) {
    setMode(next);
    setError("");
    setPassword("");
    setConfirmation("");
    setAgreed(false);
    setShowPassword(false);
    setShowConfirmation(false);
  }

  async function submit(event: FormEvent) {
    event.preventDefault();
    setError("");
    if (mode === "register" && password !== confirmation) {
      setError("Konfirmasi password tidak sama.");
      return;
    }
    if (mode === "register" && !agreed) {
      setError("Setujui Syarat & Ketentuan dan Kebijakan Privasi untuk mendaftar.");
      return;
    }
    setSaving(true);
    try {
      const response = await fetch(`/api/auth/${mode}`, {
        method: "POST",
        headers: { "content-type": "application/json" },
        body: JSON.stringify(
          mode === "register"
            ? { name, phone: phone.replace(/[\s()-]/g, ""), email, password, turnstileToken }
            : { email, password, turnstileToken },
        ),
      });
      const data = await response.json().catch(() => ({})) as { error?: string };
      if (!response.ok) throw new Error(data.error ?? "Akun gagal diproses.");
      window.dispatchEvent(new Event("lfamilia:auth-changed"));
      await onSuccess();
    } catch (reason) {
      setError(reason instanceof Error ? reason.message : "Akun gagal diproses.");
      setTurnstileToken("");
      setTurnstileReset((value) => value + 1);
    } finally {
      setSaving(false);
    }
  }

  return (
    <section className="mx-auto w-full max-w-[560px] rounded-[24px] border border-white/[0.09] bg-[#090c14]/95 p-5 shadow-[0_24px_80px_rgba(0,0,0,0.36)] sm:p-7">
      <div className="flex items-start gap-3">
        <span className="grid size-10 shrink-0 place-items-center rounded-xl bg-[#b9ff35]/10 text-[#cfff72]">
          {mode === "login" ? <LogIn className="size-5" /> : <UserPlus className="size-5" />}
        </span>
        <div>
          <h1 className="text-[24px] font-black leading-tight text-white sm:text-[28px]">
            {mode === "login" ? "Selamat datang!" : "Daftar"}
          </h1>
          <p className="mt-1.5 text-[12px] leading-5 text-white/45 sm:text-[13px]">
            {mode === "login"
              ? "Masukkan email dan kata sandi Anda untuk masuk ke akun."
              : "Lengkapi data berikut untuk membuat akun LFAMILIA baru."}
          </p>
        </div>
      </div>

      <form onSubmit={submit} className="mt-6 space-y-4">
        {mode === "register" && (
          <AuthField label="Nama lengkap" icon={<UserRound className="size-[18px]" />}>
            <input required autoComplete="name" value={name} onChange={(event) => setName(event.target.value)} placeholder="Nama lengkap" className="h-12 w-full bg-transparent pl-11 pr-4 text-sm text-white outline-none placeholder:text-white/24" />
          </AuthField>
        )}

        <AuthField label="Email" icon={<Mail className="size-[18px]" />}>
          <input required type="email" autoComplete="email" value={email} onChange={(event) => setEmail(event.target.value)} placeholder="Masukkan alamat email Anda" className="h-12 w-full bg-transparent pl-11 pr-4 text-sm text-white outline-none placeholder:text-white/24" />
        </AuthField>

        {mode === "register" && (
          <AuthField label="Nomor kontak" icon={<Phone className="size-[18px]" />}>
            <input required inputMode="tel" autoComplete="tel" value={phone} onChange={(event) => setPhone(event.target.value)} placeholder="081234567890" className="h-12 w-full bg-transparent pl-11 pr-4 text-sm text-white outline-none placeholder:text-white/24" />
          </AuthField>
        )}

        {mode === "login" && <div className="-mb-2 flex justify-end"><Link href="/forgot-password" className="text-[11px] font-bold text-[#cfff72] hover:underline">Lupa password?</Link></div>}

        <AuthField label="Password" icon={<LockKeyhole className="size-[18px]" />}>
          <input required minLength={8} type={showPassword ? "text" : "password"} autoComplete={mode === "login" ? "current-password" : "new-password"} value={password} onChange={(event) => setPassword(event.target.value)} placeholder={mode === "login" ? "Masukkan kata sandi Anda" : "Minimal 8 karakter"} className="h-12 w-full bg-transparent pl-11 pr-12 text-sm text-white outline-none placeholder:text-white/24" />
          <button type="button" onClick={() => setShowPassword((value) => !value)} aria-label={showPassword ? "Sembunyikan password" : "Tampilkan password"} className="absolute bottom-0 right-0 grid h-12 w-12 place-items-center text-white/40 transition hover:text-white/75">
            {showPassword ? <EyeOff className="size-[17px]" /> : <Eye className="size-[17px]" />}
          </button>
        </AuthField>

        {mode === "register" && (
          <AuthField label="Konfirmasi Kata Sandi" icon={<LockKeyhole className="size-[18px]" />}>
            <input required minLength={8} type={showConfirmation ? "text" : "password"} autoComplete="new-password" value={confirmation} onChange={(event) => setConfirmation(event.target.value)} placeholder="Ulangi kata sandi" className="h-12 w-full bg-transparent pl-11 pr-12 text-sm text-white outline-none placeholder:text-white/24" />
            <button type="button" onClick={() => setShowConfirmation((value) => !value)} aria-label={showConfirmation ? "Sembunyikan konfirmasi password" : "Tampilkan konfirmasi password"} className="absolute bottom-0 right-0 grid h-12 w-12 place-items-center text-white/40 transition hover:text-white/75">
              {showConfirmation ? <EyeOff className="size-[17px]" /> : <Eye className="size-[17px]" />}
            </button>
          </AuthField>
        )}

        {mode === "register" && (
          <label className="flex cursor-pointer items-start gap-3 pt-1 text-[11px] leading-5 text-white/45">
            <input type="checkbox" checked={agreed} onChange={(event) => setAgreed(event.target.checked)} className="mt-1 size-4 shrink-0 accent-[#b9ff35]" />
            <span>
              Dengan mendaftar, Anda setuju dengan{" "}
              <Link href="/terms" className="font-bold text-[#cfff72] hover:underline">Syarat & Ketentuan</Link>
              {" "}dan{" "}
              <Link href="/privacy" className="font-bold text-[#cfff72] hover:underline">Kebijakan Privasi</Link>.
            </span>
          </label>
        )}

        {error && <p className="rounded-xl border border-red-400/20 bg-red-400/[0.06] px-3 py-2.5 text-[11px] leading-5 text-red-200">{error}</p>}

        <TurnstileWidget key={turnstileReset} onToken={setTurnstileToken} />

        <Button disabled={saving} className="h-12 w-full rounded-xl bg-[#b9ff35] text-sm font-black text-[#091006] hover:bg-[#c7ff58]">
          {saving && <LoaderCircle className="mr-2 size-4 animate-spin" />}
          {mode === "login" ? "Masuk" : "Daftar"}
        </Button>
      </form>

      <div className="mt-5 text-center text-[11px] text-white/45">
        {mode === "login" ? "Belum punya akun? " : "Sudah punya akun? "}
        <button type="button" onClick={() => changeMode(mode === "login" ? "register" : "login")} className="font-black text-[#cfff72] hover:underline">
          {mode === "login" ? "Daftar" : "Masuk"}
        </button>
      </div>

      {googleClientId && (
        <>
          <div className="my-5 flex items-center gap-3 text-[10px] uppercase tracking-[0.2em] text-white/25">
            <span className="h-px flex-1 bg-white/[0.08]" />
            <span>atau</span>
            <span className="h-px flex-1 bg-white/[0.08]" />
          </div>
          <GoogleIdentityButton clientId={googleClientId} setError={setError} onSuccess={onSuccess} />
          <p className="mt-3 text-center text-[9px] leading-4 text-white/28">
            Dengan melanjutkan melalui Google, Anda menyetujui <Link href="/terms" className="text-white/45 hover:text-white">Syarat & Ketentuan</Link> dan <Link href="/privacy" className="text-white/45 hover:text-white">Kebijakan Privasi</Link>.
          </p>
        </>
      )}

      <p className="mt-5 flex items-start justify-center gap-2 text-[10px] leading-5 text-white/28">
        <ShieldCheck className="mt-0.5 size-3.5 shrink-0 text-[#b9ff35]" />
        Password diacak dan tidak pernah ditampilkan kepada admin.
      </p>
    </section>
  );
}

function GoogleIdentityButton({
  clientId,
  setError,
  onSuccess,
}: {
  clientId: string;
  setError(value: string): void;
  onSuccess(): Promise<void>;
}) {
  const containerRef = useRef<HTMLDivElement | null>(null);
  const [busy, setBusy] = useState(false);

  useEffect(() => {
    const container = containerRef.current;
    if (!container) return;
    let active = true;

    const handleCredential = async (response: GoogleCredentialResponse) => {
      if (!active || !response.credential) {
        if (active) setError("Google tidak mengembalikan credential login.");
        return;
      }
      setBusy(true);
      setError("");
      try {
        const result = await fetch("/api/auth/google", {
          method: "POST",
          headers: { "content-type": "application/json" },
          body: JSON.stringify({ credential: response.credential }),
        });
        const payload = await result.json().catch(() => ({})) as { error?: string };
        if (!result.ok) throw new Error(payload.error || "Login Google gagal.");
        window.dispatchEvent(new Event("lfamilia:auth-changed"));
        await onSuccess();
      } catch (reason) {
        if (active) setError(reason instanceof Error ? reason.message : "Login Google gagal.");
      } finally {
        if (active) setBusy(false);
      }
    };

    const render = () => {
      if (!active || !window.google?.accounts.id || !containerRef.current) return;
      container.replaceChildren();
      window.google.accounts.id.initialize({
        client_id: clientId,
        callback: (response) => { void handleCredential(response); },
        auto_select: false,
        cancel_on_tap_outside: true,
      });
      const width = Math.max(240, Math.min(400, container.clientWidth || 320));
      window.google.accounts.id.renderButton(container, {
        type: "standard",
        theme: "outline",
        size: "large",
        text: "continue_with",
        shape: "rectangular",
        logo_alignment: "left",
        width,
      });
    };

    const stale = document.querySelector<HTMLScriptElement>('script[data-lf-google-identity="true"][data-lf-google-state="failed"]');
    stale?.remove();
    const existing = document.querySelector<HTMLScriptElement>('script[data-lf-google-identity="true"]');
    const onScriptError = () => {
      const script = document.querySelector<HTMLScriptElement>('script[data-lf-google-identity="true"]');
      if (script) script.dataset.lfGoogleState = "failed";
      if (active) setError("Google Login gagal dimuat. Coba lagi.");
    };
    if (window.google?.accounts.id) {
      render();
    } else if (existing) {
      existing.addEventListener("load", render, { once: true });
      existing.addEventListener("error", onScriptError, { once: true });
    } else {
      const script = document.createElement("script");
      script.src = "https://accounts.google.com/gsi/client";
      script.async = true;
      script.defer = true;
      script.dataset.lfGoogleIdentity = "true";
      script.dataset.lfGoogleState = "loading";
      script.addEventListener("load", () => {
        script.dataset.lfGoogleState = "loaded";
        render();
      }, { once: true });
      script.addEventListener("error", onScriptError, { once: true });
      document.head.appendChild(script);
    }

    return () => {
      active = false;
      existing?.removeEventListener("load", render);
      existing?.removeEventListener("error", onScriptError);
      container.replaceChildren();
    };
  }, [clientId, onSuccess, setError]);

  return (
    <div className="relative flex min-h-11 w-full items-center justify-center overflow-hidden rounded-xl">
      <div ref={containerRef} className={busy ? "pointer-events-none opacity-60" : ""} />
      {busy && <LoaderCircle className="absolute right-3 size-4 animate-spin text-white/45" />}
    </div>
  );
}

function AuthField({
  label,
  icon,
  children,
}: {
  label: string;
  icon: React.ReactNode;
  children: React.ReactNode;
}) {
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
