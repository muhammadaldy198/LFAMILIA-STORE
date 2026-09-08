"use client";
/* eslint-disable @next/next/no-img-element */

import { useCallback, useEffect, useState } from "react";
import { Eye, LoaderCircle, Save } from "lucide-react";
import { AdminMediaUpload } from "@/components/admin-media-upload";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Switch } from "@/components/ui/switch";
import {
  defaultPaymentPageSettings,
  type PaymentPageSettings,
} from "@/lib/payment-page-settings";

export function AdminPaymentPageManager() {
  const [settings, setSettings] = useState<PaymentPageSettings>(defaultPaymentPageSettings);
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [message, setMessage] = useState("");
  const [error, setError] = useState("");

  const load = useCallback(async () => {
    setLoading(true);
    setError("");
    try {
      const response = await fetch("/api/panel/payment-page", { cache: "no-store" });
      const data = await readJson(response);
      if (!response.ok) throw new Error(String(data.error || "Pengaturan halaman pembayaran gagal dimuat."));
      setSettings((data.settings as PaymentPageSettings | undefined) ?? defaultPaymentPageSettings);
    } catch (reason) {
      setError(reason instanceof Error ? reason.message : "Pengaturan halaman pembayaran gagal dimuat.");
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    const timer = window.setTimeout(() => void load(), 0);
    return () => window.clearTimeout(timer);
  }, [load]);

  function patch<K extends keyof PaymentPageSettings>(key: K, value: PaymentPageSettings[K]) {
    setSettings((current) => ({ ...current, [key]: value }));
  }

  async function save() {
    setSaving(true);
    setMessage("");
    setError("");
    try {
      const response = await fetch("/api/panel/payment-page", {
        method: "PUT",
        headers: { "content-type": "application/json" },
        body: JSON.stringify(settings),
      });
      const data = await readJson(response);
      if (!response.ok) throw new Error(String(data.error || "Pengaturan halaman pembayaran gagal disimpan."));
      setSettings((data.settings as PaymentPageSettings | undefined) ?? settings);
      setMessage("Halaman pembayaran berhasil diperbarui.");
    } catch (reason) {
      setError(reason instanceof Error ? reason.message : "Pengaturan halaman pembayaran gagal disimpan.");
    } finally {
      setSaving(false);
    }
  }

  if (loading) {
    return <div className="flex min-h-32 items-center justify-center text-xs text-white/35"><LoaderCircle className="mr-2 size-4 animate-spin" />Memuat halaman pembayaran…</div>;
  }

  return (
    <div className="space-y-3">
      {message && <div className="rounded-md border border-[#b9ff35]/20 bg-[#b9ff35]/[0.05] px-3 py-2 text-xs text-[#d8ff8d]">{message}</div>}
      {error && <div className="rounded-md border border-red-400/20 bg-red-400/[0.05] px-3 py-2 text-xs text-red-100">{error}</div>}

      <div className="grid gap-3 lg:grid-cols-[minmax(0,1fr)_320px]">
        <div className="space-y-3">
          <Section title="Brand & header">
            <div className="grid gap-2 sm:grid-cols-2">
              <Field label="Label kecil"><Input className="admin-input h-9" value={settings.eyebrow} onChange={(event) => patch("eyebrow", event.target.value)} /></Field>
              <Field label="Warna aksen"><Input className="admin-input h-9 font-mono" value={settings.accentColor} onChange={(event) => patch("accentColor", event.target.value)} /></Field>
              <div className="sm:col-span-2"><AdminMediaUpload label="Gambar header opsional" value={settings.headerImageUrl} onChange={(value) => patch("headerImageUrl", value)} help="Kosongkan jika hanya memakai branding toko." previewClassName="h-24" /></div>
              <Toggle label="Tampilkan logo/nama toko" checked={settings.showStoreBrand} onChange={(value) => patch("showStoreBrand", value)} />
            </div>
          </Section>

          <Section title="Judul & pesan status">
            <div className="grid gap-2 sm:grid-cols-2">
              <Field label="Judul menunggu"><Input className="admin-input h-9" value={settings.pendingTitle} onChange={(e) => patch("pendingTitle", e.target.value)} /></Field>
              <Field label="Judul berhasil"><Input className="admin-input h-9" value={settings.paidTitle} onChange={(e) => patch("paidTitle", e.target.value)} /></Field>
              <Field label="Judul gagal/kadaluarsa"><Input className="admin-input h-9" value={settings.failedTitle} onChange={(e) => patch("failedTitle", e.target.value)} /></Field>
              <Field label="Subjudul"><Input className="admin-input h-9" value={settings.subtitle} onChange={(e) => patch("subtitle", e.target.value)} /></Field>
              <TextField label="Pesan status menunggu" value={settings.pendingStatusText} onChange={(value) => patch("pendingStatusText", value)} />
              <TextField label="Pesan status berhasil" value={settings.paidStatusText} onChange={(value) => patch("paidStatusText", value)} />
              <TextField label="Pesan status gagal" value={settings.failedStatusText} onChange={(value) => patch("failedStatusText", value)} />
              <Toggle label="Tampilkan panel status" checked={settings.showStatusBox} onChange={(value) => patch("showStatusBox", value)} />
            </div>
          </Section>

          <Section title="Invoice & ringkasan">
            <div className="grid gap-2 sm:grid-cols-2">
              <Field label="Judul pengingat invoice"><Input className="admin-input h-9" value={settings.invoiceNoticeTitle} onChange={(e) => patch("invoiceNoticeTitle", e.target.value)} /></Field>
              <TextField label="Isi pengingat invoice" value={settings.invoiceNoticeText} onChange={(value) => patch("invoiceNoticeText", value)} />
              <Toggle label="Tampilkan pengingat invoice" checked={settings.showInvoiceNotice} onChange={(value) => patch("showInvoiceNotice", value)} />
              <Toggle label="Tampilkan ringkasan pesanan" checked={settings.showOrderSummary} onChange={(value) => patch("showOrderSummary", value)} />
            </div>
          </Section>

          <Section title="Tombol & bantuan">
            <div className="grid gap-2 sm:grid-cols-2">
              <Field label="Teks tombol bayar"><Input className="admin-input h-9" value={settings.payButtonText} onChange={(e) => patch("payButtonText", e.target.value)} /></Field>
              <Field label="Teks tombol cek status"><Input className="admin-input h-9" value={settings.checkStatusButtonText} onChange={(e) => patch("checkStatusButtonText", e.target.value)} /></Field>
              <Field label="Teks tombol cek invoice"><Input className="admin-input h-9" value={settings.checkInvoiceButtonText} onChange={(e) => patch("checkInvoiceButtonText", e.target.value)} /></Field>
              <Field label="Teks bantuan"><Input className="admin-input h-9" value={settings.supportText} onChange={(e) => patch("supportText", e.target.value)} /></Field>
              <Field label="URL bantuan"><Input className="admin-input h-9" value={settings.supportUrl} onChange={(e) => patch("supportUrl", e.target.value)} /></Field>
              <Toggle label="Tampilkan bantuan" checked={settings.showSupport} onChange={(value) => patch("showSupport", value)} />
            </div>
          </Section>
        </div>

        <aside className="h-fit rounded-lg border border-white/[0.08] bg-[#0a0d14] p-3 lg:sticky lg:top-20">
          <div className="mb-3 flex items-center gap-2 text-[10px] font-bold text-white/55"><Eye className="size-3.5" />Preview ringkas</div>
          <div className="overflow-hidden rounded-xl border border-white/[0.08] bg-[#15181f]">
            {settings.headerImageUrl ? <img src={settings.headerImageUrl} alt="" className="h-24 w-full object-cover" /> : null}
            <div className="p-4">
              <p className="text-[8px] font-black uppercase tracking-[0.18em]" style={{ color: settings.accentColor }}>{settings.eyebrow}</p>
              <h3 className="mt-1 text-base font-black">{settings.pendingTitle}</h3>
              <p className="mt-1 text-[9px] leading-4 text-white/40">{settings.subtitle}</p>
              {settings.showInvoiceNotice && <div className="mt-3 rounded-lg border border-white/[0.08] bg-white/[0.025] p-2"><strong className="text-[9px]">{settings.invoiceNoticeTitle}</strong><p className="mt-1 text-[8px] leading-3 text-white/35">{settings.invoiceNoticeText}</p></div>}
              <button type="button" className="mt-3 h-9 w-full rounded-lg text-[9px] font-black text-[#091006]" style={{ backgroundColor: settings.accentColor }}>{settings.payButtonText}</button>
            </div>
          </div>
        </aside>
      </div>

      <div className="flex justify-end border-t border-white/[0.08] pt-3">
        <Button type="button" disabled={saving} onClick={() => void save()} className="bg-[#b9ff35] text-[#091006] hover:bg-[#d8ff8d]">
          {saving ? <LoaderCircle className="size-3.5 animate-spin" /> : <Save className="size-3.5" />}
          {saving ? "Menyimpan…" : "Simpan halaman pembayaran"}
        </Button>
      </div>
    </div>
  );
}

function Section({ title, children }: { title: string; children: React.ReactNode }) {
  return <section className="rounded-lg border border-white/[0.08] bg-white/[0.015] p-3"><h3 className="mb-3 text-xs font-bold">{title}</h3>{children}</section>;
}

function Field({ label, children }: { label: string; children: React.ReactNode }) {
  return <label><span className="field-label">{label}</span>{children}</label>;
}

function TextField({ label, value, onChange }: { label: string; value: string; onChange(value: string): void }) {
  return <label><span className="field-label">{label}</span><textarea className="admin-input min-h-20 resize-y py-2 text-[10px]" value={value} onChange={(event) => onChange(event.target.value)} /></label>;
}

function Toggle({ label, checked, onChange }: { label: string; checked: boolean; onChange(value: boolean): void }) {
  return <label className="flex items-center justify-between gap-3 rounded-md border border-white/[0.07] bg-white/[0.02] px-3 py-2"><span className="text-[10px] text-white/55">{label}</span><Switch checked={checked} onCheckedChange={onChange} /></label>;
}

async function readJson(response: Response): Promise<Record<string, unknown>> {
  const raw = await response.text();
  try {
    return raw ? JSON.parse(raw) as Record<string, unknown> : { error: "Respons server kosong." };
  } catch {
    return { error: "Respons server tidak valid." };
  }
}
