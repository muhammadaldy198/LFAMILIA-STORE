"use client";

import { FormEvent, useState } from "react";
import { Camera, Check, Clipboard, Clock3, ExternalLink, Mail, MessageCircle, MessagesSquare, Send, ShieldCheck } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { useStorefront } from "@/hooks/use-storefront";

export function ContactPanel() {
  const { settings } = useStorefront();
  const [requestType, setRequestType] = useState("Kendala transaksi");
  const [name, setName] = useState("");
  const [phone, setPhone] = useState("");
  const [invoice, setInvoice] = useState("");
  const [message, setMessage] = useState("");
  const [copied, setCopied] = useState(false);

  const whatsapp = settings.supportWhatsapp?.replace(/\D/g, "").replace(/^0/, "62");
  const preparedMessage = `Halo ${settings.storeName}, saya membutuhkan bantuan.\n\nJenis bantuan: ${requestType}\nNama: ${name}\nNomor WhatsApp: ${phone}\nInvoice: ${invoice || "-"}\nKeterangan: ${message}`;

  async function copyPreparedMessage() {
    await navigator.clipboard.writeText(preparedMessage);
    setCopied(true);
    window.setTimeout(() => setCopied(false), 2500);
  }

  async function sendMessage(event: FormEvent) {
    event.preventDefault();
    if (whatsapp) {
      window.open(`https://wa.me/${whatsapp}?text=${encodeURIComponent(preparedMessage)}`, "_blank", "noopener,noreferrer");
      return;
    }
    if (settings.supportEmail) {
      const subject = invoice ? `${requestType} · ${invoice}` : requestType;
      window.location.href = `mailto:${settings.supportEmail}?subject=${encodeURIComponent(subject)}&body=${encodeURIComponent(preparedMessage)}`;
      return;
    }
    await copyPreparedMessage();
  }

  const channels = [
    { icon: MessageCircle, title: "WhatsApp", text: settings.supportWhatsapp || "Belum diaktifkan", href: whatsapp ? `https://wa.me/${whatsapp}` : undefined, action: "Mulai chat" },
    { icon: Mail, title: "Email", text: settings.supportEmail || "Belum diaktifkan", href: settings.supportEmail ? `mailto:${settings.supportEmail}` : undefined, action: "Kirim email" },
    { icon: Camera, title: "Instagram", text: settings.instagramUrl ? settings.instagramUrl.replace(/^https?:\/\/(www\.)?instagram\.com\//i, "@").replace(/\/$/, "") : "Belum diaktifkan", href: settings.instagramUrl, action: "Buka profil" },
    { icon: MessagesSquare, title: "Discord", text: settings.discordUrl ? "Komunitas LFAMILIA" : "Belum diaktifkan", href: settings.discordUrl, action: "Gabung server" },
  ];

  return (
    <div className="grid gap-5 lg:grid-cols-[.82fr_1.18fr]">
      <div className="space-y-3">
        {channels.map(({ icon: Icon, title, text, href, action }) => {
          const content = <><span className={`grid size-11 shrink-0 place-items-center rounded-xl ${href ? "bg-[#b9ff35]/[0.09] text-[#cfff72]" : "bg-white/[0.035] text-white/25"}`}><Icon className="size-5" /></span><div className="min-w-0 flex-1"><h2 className="text-sm font-black">{title}</h2><p className="mt-1 truncate text-xs text-white/38">{text}</p></div>{href && <span className="hidden items-center gap-1 text-[10px] font-bold text-white/30 transition group-hover:text-[#b9ff35] sm:flex">{action}<ExternalLink className="size-3" /></span>}</>;
          return href ? <a key={title} href={href} target={href.startsWith("http") ? "_blank" : undefined} rel={href.startsWith("http") ? "noreferrer" : undefined} className="group flex items-center gap-4 rounded-2xl border border-white/[0.08] bg-[#10131b] p-5 transition hover:border-[#b9ff35]/30 hover:bg-[#121720]">{content}</a> : <div key={title} aria-disabled="true" className="flex items-center gap-4 rounded-2xl border border-white/[0.06] bg-[#10131b]/70 p-5 opacity-75">{content}</div>;
        })}
        <div className="flex items-start gap-3 rounded-2xl border border-white/[0.08] bg-white/[0.02] p-5"><Clock3 className="mt-0.5 size-4 text-[#b9ff35]" /><p className="text-xs leading-5 text-white/38">Jam dukungan: {settings.supportHours}</p></div>
      </div>

      <form onSubmit={sendMessage} className="rounded-[26px] border border-white/[0.09] bg-[#10131b] p-5 sm:p-8">
        <p className="eyebrow">Formulir bantuan</p><h2 className="text-xl font-black">Ceritakan kendalamu</h2><p className="mt-2 text-xs leading-5 text-white/38">Formulir ini akan menyiapkan pesan lengkap lalu membuka kanal bantuan resmi yang tersedia.</p>
        <label className="mt-6 block"><span className="field-label">Jenis bantuan</span><select value={requestType} onChange={(event) => setRequestType(event.target.value)} className="h-12 w-full rounded-xl border border-white/10 bg-[#171c27] px-3 text-sm text-white outline-none focus:border-[#b9ff35]/45"><option>Kendala transaksi</option><option>Status pesanan</option><option>Pembayaran</option><option>Permintaan refund</option><option>Produk atau harga</option><option>Lainnya</option></select></label>
        <div className="mt-4 grid gap-4 sm:grid-cols-2"><label><span className="field-label">Nama</span><Input required value={name} onChange={(event) => setName(event.target.value)} placeholder="Nama kamu" className="h-12 rounded-xl border-white/10 bg-white/[0.035] text-white placeholder:text-white/22" /></label><label><span className="field-label">Nomor WhatsApp</span><Input required inputMode="tel" value={phone} onChange={(event) => setPhone(event.target.value)} placeholder="08xxxxxxxxxx" className="h-12 rounded-xl border-white/10 bg-white/[0.035] text-white placeholder:text-white/22" /></label></div>
        <label className="mt-4 block"><span className="field-label">Nomor invoice (opsional)</span><Input value={invoice} onChange={(event) => setInvoice(event.target.value)} placeholder="LF-2026..." className="h-12 rounded-xl border-white/10 bg-white/[0.035] text-white placeholder:text-white/22" /></label>
        <label className="mt-4 block"><span className="field-label">Pesan</span><textarea required value={message} onChange={(event) => setMessage(event.target.value)} rows={5} placeholder="Jelaskan pertanyaan atau masalah..." className="w-full resize-none rounded-xl border border-white/10 bg-white/[0.035] p-3 text-sm text-white outline-none placeholder:text-white/22 focus:border-[#b9ff35]/45" /></label>
        <div className="mt-5 grid gap-3 sm:grid-cols-[1fr_auto]">
          <Button type="submit" className="h-12 rounded-xl bg-[#b9ff35] font-black text-[#091006] hover:bg-[#d0ff75]"><Send className="mr-2 size-4" />{whatsapp ? "Kirim lewat WhatsApp" : settings.supportEmail ? "Kirim lewat email" : "Salin pesan bantuan"}</Button>
          <Button type="button" onClick={() => void copyPreparedMessage()} variant="outline" className="h-12 rounded-xl border-white/10 bg-white/[0.025] px-5 text-white hover:bg-white/[0.08] hover:text-white">{copied ? <Check className="mr-2 size-4" /> : <Clipboard className="mr-2 size-4" />}{copied ? "Tersalin" : "Salin"}</Button>
        </div>
        <p className="mt-5 flex items-start gap-2 text-[10px] leading-5 text-white/30"><ShieldCheck className="mt-0.5 size-3.5 shrink-0 text-[#b9ff35]" />Jangan cantumkan password, PIN, kode OTP, atau data kartu pembayaran. Informasi yang kamu isi hanya disusun di perangkatmu sampai kamu memilih kanal tujuan.</p>
      </form>
    </div>
  );
}
