"use client";

import { FormEvent, useState } from "react";
import { Check, Clipboard, Clock3, ExternalLink, Mail, Send, ShieldCheck } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { useStorefront } from "@/hooks/use-storefront";


function WhatsAppLogo({ className = "" }: { className?: string }) {
  return <svg viewBox="0 0 24 24" aria-hidden="true" className={className} fill="currentColor"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413Z"/></svg>;
}

function InstagramLogo({ className = "" }: { className?: string }) {
  return <svg viewBox="0 0 24 24" aria-hidden="true" className={className} fill="currentColor"><path d="M7.0301.084c-1.2768.0602-2.1487.264-2.911.5634-.7888.3075-1.4575.72-2.1228 1.3877-.6652.6677-1.075 1.3368-1.3802 2.127-.2954.7638-.4956 1.6365-.552 2.914-.0564 1.2775-.0689 1.6882-.0626 4.947.0062 3.2586.0206 3.6671.0825 4.9473.061 1.2765.264 2.1482.5635 2.9107.308.7889.72 1.4573 1.388 2.1228.6679.6655 1.3365 1.0743 2.1285 1.38.7632.295 1.6361.4961 2.9134.552 1.2773.056 1.6884.069 4.9462.0627 3.2578-.0062 3.668-.0207 4.9478-.0814 1.28-.0607 2.147-.2652 2.9098-.5633.7889-.3086 1.4578-.72 2.1228-1.3881.665-.6682 1.0745-1.3378 1.3795-2.1284.2957-.7632.4966-1.636.552-2.9124.056-1.2809.0692-1.6898.063-4.948-.0063-3.2583-.021-3.6668-.0817-4.9465-.0607-1.2797-.264-2.1487-.5633-2.9117-.3084-.7889-.72-1.4568-1.3876-2.1228C21.2982 1.33 20.628.9208 19.8378.6165 19.074.321 18.2017.1197 16.9244.0645 15.6471.0093 15.236-.005 11.977.0014 8.718.0076 8.31.0215 7.0301.0839m.1402 21.6932c-1.17-.0509-1.8053-.2453-2.2287-.408-.5606-.216-.96-.4771-1.3819-.895-.422-.4178-.6811-.8186-.9-1.378-.1644-.4234-.3624-1.058-.4171-2.228-.0595-1.2645-.072-1.6442-.079-4.848-.007-3.2037.0053-3.583.0607-4.848.05-1.169.2456-1.805.408-2.2282.216-.5613.4762-.96.895-1.3816.4188-.4217.8184-.6814 1.3783-.9003.423-.1651 1.0575-.3614 2.227-.4171 1.2655-.06 1.6447-.072 4.848-.079 3.2033-.007 3.5835.005 4.8495.0608 1.169.0508 1.8053.2445 2.228.408.5608.216.96.4754 1.3816.895.4217.4194.6816.8176.9005 1.3787.1653.4217.3617 1.056.4169 2.2263.0602 1.2655.0739 1.645.0796 4.848.0058 3.203-.0055 3.5834-.061 4.848-.051 1.17-.245 1.8055-.408 2.2294-.216.5604-.4763.96-.8954 1.3814-.419.4215-.8181.6811-1.3783.9-.4224.1649-1.0577.3617-2.2262.4174-1.2656.0595-1.6448.072-4.8493.079-3.2045.007-3.5825-.006-4.848-.0608M16.953 5.5864A1.44 1.44 0 1 0 18.39 4.144a1.44 1.44 0 0 0-1.437 1.4424M5.8385 12.012c.0067 3.4032 2.7706 6.1557 6.173 6.1493 3.4026-.0065 6.157-2.7701 6.1506-6.1733-.0065-3.4032-2.771-6.1565-6.174-6.1498-3.403.0067-6.156 2.771-6.1496 6.1738M8 12.0077a4 4 0 1 1 4.008 3.9921A3.9996 3.9996 0 0 1 8 12.0077"/></svg>;
}

function DiscordLogo({ className = "" }: { className?: string }) {
  return <svg viewBox="0 0 24 24" aria-hidden="true" className={className} fill="currentColor"><path d="M20.317 4.3698a19.7913 19.7913 0 00-4.8851-1.5152.0741.0741 0 00-.0785.0371c-.211.3753-.4447.8648-.6083 1.2495-1.8447-.2762-3.68-.2762-5.4868 0-.1636-.3933-.4058-.8742-.6177-1.2495a.077.077 0 00-.0785-.037 19.7363 19.7363 0 00-4.8852 1.515.0699.0699 0 00-.0321.0277C.5334 9.0458-.319 13.5799.0992 18.0578a.0824.0824 0 00.0312.0561c2.0528 1.5076 4.0413 2.4228 5.9929 3.0294a.0777.0777 0 00.0842-.0276c.4616-.6304.8731-1.2952 1.226-1.9942a.076.076 0 00-.0416-.1057c-.6528-.2476-1.2743-.5495-1.8722-.8923a.077.077 0 01-.0076-.1277c.1258-.0943.2517-.1923.3718-.2914a.0743.0743 0 01.0776-.0105c3.9278 1.7933 8.18 1.7933 12.0614 0a.0739.0739 0 01.0785.0095c.1202.099.246.1981.3728.2924a.077.077 0 01-.0066.1276 12.2986 12.2986 0 01-1.873.8914.0766.0766 0 00-.0407.1067c.3604.698.7719 1.3628 1.225 1.9932a.076.076 0 00.0842.0286c1.961-.6067 3.9495-1.5219 6.0023-3.0294a.077.077 0 00.0313-.0552c.5004-5.177-.8382-9.6739-3.5485-13.6604a.061.061 0 00-.0312-.0286zM8.02 15.3312c-1.1825 0-2.1569-1.0857-2.1569-2.419 0-1.3332.9555-2.4189 2.157-2.4189 1.2108 0 2.1757 1.0952 2.1568 2.419 0 1.3332-.9555 2.4189-2.1569 2.4189zm7.9748 0c-1.1825 0-2.1569-1.0857-2.1569-2.419 0-1.3332.9554-2.4189 2.1569-2.4189 1.2108 0 2.1757 1.0952 2.1568 2.419 0 1.3332-.946 2.4189-2.1568 2.4189Z"/></svg>;
}

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
    { icon: WhatsAppLogo, title: "WhatsApp", text: settings.supportWhatsapp || "Belum diaktifkan", href: whatsapp ? `https://wa.me/${whatsapp}` : undefined, action: "Mulai chat" },
    { icon: Mail, title: "Email", text: settings.supportEmail || "Belum diaktifkan", href: settings.supportEmail ? `mailto:${settings.supportEmail}` : undefined, action: "Kirim email" },
    { icon: InstagramLogo, title: "Instagram", text: settings.instagramUrl ? settings.instagramUrl.replace(/^https?:\/\/(www\.)?instagram\.com\//i, "@").replace(/\/$/, "") : "Belum diaktifkan", href: settings.instagramUrl, action: "Buka profil" },
    { icon: DiscordLogo, title: "Discord", text: settings.discordUrl ? "Komunitas LFAMILIA" : "Belum diaktifkan", href: settings.discordUrl, action: "Gabung server" },
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
        <label className="mt-4 block"><span className="field-label">Nomor invoice (opsional)</span><Input value={invoice} onChange={(event) => setInvoice(event.target.value.toUpperCase().replace(/\s/g, ""))} placeholder="Contoh: LF5330FEABAE05" className="h-12 rounded-xl border-white/10 bg-white/[0.035] font-mono uppercase text-white placeholder:font-sans placeholder:text-white/22" /></label>
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
