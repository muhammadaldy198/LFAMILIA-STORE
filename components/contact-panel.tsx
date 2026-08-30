"use client";

import { FormEvent, useState } from "react";
import { Camera, Check, Clipboard, Clock3, Mail, MessageCircle, MessagesSquare } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { useStorefront } from "@/hooks/use-storefront";

export function ContactPanel() {
  const { settings } = useStorefront();
  const [name, setName] = useState("");
  const [invoice, setInvoice] = useState("");
  const [message, setMessage] = useState("");
  const [copied, setCopied] = useState(false);

  async function copyMessage(event: FormEvent) {
    event.preventDefault();
    const text = `Halo ${settings.storeName}\nNama: ${name}\nInvoice: ${invoice || "-"}\nPesan: ${message}`;
    await navigator.clipboard.writeText(text);
    setCopied(true);
    window.setTimeout(() => setCopied(false), 2500);
  }

  const whatsapp = settings.supportWhatsapp?.replace(/\D/g, "").replace(/^0/, "62");
  const channels = [
    ...(whatsapp ? [{ icon: MessageCircle, title: "WhatsApp", text: settings.supportWhatsapp!, href: `https://wa.me/${whatsapp}` }] : []),
    ...(settings.instagramUrl ? [{ icon: Camera, title: "Instagram", text: settings.instagramUrl.replace(/^https?:\/\/(www\.)?instagram\.com\//i, "@").replace(/\/$/, ""), href: settings.instagramUrl }] : []),
    ...(settings.discordUrl ? [{ icon: MessagesSquare, title: "Discord", text: "Gabung server komunitas LFAMILIA", href: settings.discordUrl }] : []),
    ...(settings.supportEmail ? [{ icon: Mail, title: "Email", text: settings.supportEmail, href: `mailto:${settings.supportEmail}` }] : []),
  ];

  return (
    <div className="grid gap-5 lg:grid-cols-[.85fr_1.15fr]">
      <div className="space-y-3">
        {channels.length ? channels.map(({ icon: Icon, title, text, href }) => <a key={title} href={href} target={href.startsWith("http") ? "_blank" : undefined} rel={href.startsWith("http") ? "noreferrer" : undefined} className="flex items-center gap-4 rounded-2xl border border-white/[0.08] bg-[#10131b] p-5 transition hover:border-[#b9ff35]/30"><span className="grid size-11 shrink-0 place-items-center rounded-xl bg-[#b9ff35]/[0.09] text-[#cfff72]"><Icon className="size-5" /></span><div className="min-w-0"><h2 className="text-sm font-black">{title}</h2><p className="mt-1 truncate text-xs text-white/34">{text}</p></div></a>) : <div className="rounded-2xl border border-white/[0.08] bg-[#10131b] p-5"><h2 className="text-sm font-black">Kanal dukungan sedang offline</h2><p className="mt-2 text-xs leading-5 text-white/38">Cek kembali halaman ini sebelum mengirim pertanyaan.</p></div>}
        <div className="flex items-start gap-3 rounded-2xl border border-white/[0.08] bg-white/[0.02] p-5"><Clock3 className="mt-0.5 size-4 text-[#b9ff35]" /><p className="text-xs leading-5 text-white/38">Jam dukungan: {settings.supportHours}</p></div>
      </div>

      <form onSubmit={copyMessage} className="rounded-[26px] border border-white/[0.09] bg-[#10131b] p-5 sm:p-8">
        <p className="eyebrow">Siapkan pesan</p><h2 className="text-xl font-black">Ceritakan kendalamu</h2><p className="mt-2 text-xs leading-5 text-white/34">Isi formulir, salin pesannya, lalu pilih salah satu kanal bantuan yang tersedia.</p>
        <div className="mt-6 grid gap-4 sm:grid-cols-2"><label><span className="field-label">Nama</span><Input required value={name} onChange={(event) => setName(event.target.value)} placeholder="Nama kamu" className="h-12 rounded-xl border-white/10 bg-white/[0.035] text-white placeholder:text-white/22" /></label><label><span className="field-label">Nomor invoice (opsional)</span><Input value={invoice} onChange={(event) => setInvoice(event.target.value)} placeholder="LF-2026..." className="h-12 rounded-xl border-white/10 bg-white/[0.035] text-white placeholder:text-white/22" /></label></div>
        <label className="mt-4 block"><span className="field-label">Pesan</span><textarea required value={message} onChange={(event) => setMessage(event.target.value)} rows={5} placeholder="Jelaskan pertanyaan atau masalah..." className="w-full resize-none rounded-xl border border-white/10 bg-white/[0.035] p-3 text-sm text-white outline-none placeholder:text-white/22 focus:border-[#b9ff35]/45" /></label>
        <Button className="mt-5 h-12 w-full rounded-xl bg-[#b9ff35] font-black text-[#091006] hover:bg-[#d0ff75]">{copied ? <Check className="mr-2 size-4" /> : <Clipboard className="mr-2 size-4" />}{copied ? "Pesan tersalin" : "Salin pesan bantuan"}</Button>
      </form>
    </div>
  );
}
