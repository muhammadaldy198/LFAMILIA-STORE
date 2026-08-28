"use client";

import { FormEvent, Suspense, useMemo, useState } from "react";
import Link from "next/link";
import { useSearchParams } from "next/navigation";
import { AlertCircle, ArrowLeft, BadgeCheck, CheckCircle2, CreditCard, Info, LockKeyhole, QrCode, ShieldCheck } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { StoreLayout } from "@/components/store-layout";
import { formatRupiah, products } from "@/lib/store-data";

export default function CheckoutPage() {
  return (
    <Suspense fallback={<StoreLayout><main className="mx-auto min-h-[70vh] max-w-7xl px-4 py-14 text-sm text-white/40">Memuat formulir pemesanan…</main></StoreLayout>}>
      <CheckoutContent />
    </Suspense>
  );
}

function CheckoutContent() {
  const searchParams = useSearchParams();
  const requestedProduct = searchParams.get("product");
  const initialProduct = requestedProduct && products.some((item) => item.slug === requestedProduct) ? requestedProduct : products[0].slug;
  const [productSlug, setProductSlug] = useState(initialProduct);
  const [packageId, setPackageId] = useState("");
  const [destination, setDestination] = useState("");
  const [server, setServer] = useState("");
  const [contact, setContact] = useState("");
  const [agreed, setAgreed] = useState(false);
  const [created, setCreated] = useState(false);
  const [error, setError] = useState("");

  const product = useMemo(() => products.find((item) => item.slug === productSlug) ?? products[0], [productSlug]);
  const selectedPackage = product.packages.find((item) => item.id === packageId);
  const subtotal = selectedPackage?.price ?? 0;
  const adminFee = subtotal ? Math.ceil(subtotal / (1 - 0.007) - subtotal) : 0;
  const total = subtotal + adminFee;

  function changeProduct(slug: string) {
    setProductSlug(slug); setPackageId(""); setDestination(""); setServer(""); setCreated(false); setError("");
  }

  function submitOrder(event: FormEvent) {
    event.preventDefault();
    if (!destination.trim() || (product.needsServer && !server.trim()) || !contact.trim() || !packageId || !agreed) {
      setError("Lengkapi data tujuan, nominal, kontak, dan persetujuan terlebih dahulu.");
      return;
    }
    setError(""); setCreated(true);
  }

  return (
    <StoreLayout>
      <main className="mx-auto max-w-7xl px-4 py-10 sm:px-6 sm:py-14 lg:px-8">
        <Link href="/catalog" className="inline-flex items-center gap-2 text-xs font-semibold text-white/42 hover:text-white"><ArrowLeft className="size-4" /> Kembali ke katalog</Link>
        <div className="mt-7 grid items-start gap-6 lg:grid-cols-[1fr_380px]">
          <form onSubmit={submitOrder} className="space-y-5">
            <section className="panel overflow-hidden">
              <div className="flex items-center gap-4 border-b border-white/[0.08] p-5 sm:p-6"><span className={`grid size-14 shrink-0 place-items-center rounded-2xl bg-gradient-to-br ${product.accent} text-xl font-black`}>{product.initials}</span><div className="min-w-0"><p className="text-[10px] font-bold uppercase tracking-[0.2em] text-[#b9ff35]">Form pemesanan</p><h1 className="mt-1 truncate text-xl font-black sm:text-2xl">{product.name}</h1><p className="mt-1 text-xs text-white/36">{product.publisher}</p></div></div>
              <div className="p-5 sm:p-6">
                <label className="field-label" htmlFor="product">Pilih produk</label>
                <select id="product" value={productSlug} onChange={(event) => changeProduct(event.target.value)} className="h-11 w-full rounded-xl border border-white/10 bg-[#151924] px-3 text-sm text-white outline-none focus:border-[#b9ff35]">{products.map((item) => <option value={item.slug} key={item.slug}>{item.name}</option>)}</select>
              </div>
            </section>

            <section className="panel p-5 sm:p-6"><div className="mb-5 flex items-center gap-3"><span className="grid size-7 place-items-center rounded-lg bg-[#b9ff35] text-xs font-black text-[#091006]">1</span><div><h2 className="font-bold">Masukkan data tujuan</h2><p className="mt-0.5 text-[11px] text-white/35">Pastikan data benar sebelum melanjutkan.</p></div></div><div className={product.needsServer ? "grid gap-4 sm:grid-cols-2" : "grid gap-4"}><div><label className="field-label" htmlFor="destination">{product.inputLabel}</label><Input id="destination" value={destination} onChange={(event) => setDestination(event.target.value)} placeholder={product.inputPlaceholder} className="h-11 rounded-xl border-white/10 bg-white/[0.035] text-white placeholder:text-white/22" /></div>{product.needsServer && <div><label className="field-label" htmlFor="server">Server / Zone ID</label><Input id="server" value={server} onChange={(event) => setServer(event.target.value)} placeholder="Contoh: 1234" className="h-11 rounded-xl border-white/10 bg-white/[0.035] text-white placeholder:text-white/22" /></div>}</div></section>

            <section className="panel p-5 sm:p-6"><div className="mb-5 flex items-center gap-3"><span className="grid size-7 place-items-center rounded-lg bg-[#b9ff35] text-xs font-black text-[#091006]">2</span><div><h2 className="font-bold">Pilih nominal</h2><p className="mt-0.5 text-[11px] text-white/35">Semua harga masih data demo.</p></div></div><div className="grid grid-cols-2 gap-3 sm:grid-cols-3">{product.packages.map((item) => <button key={item.id} type="button" onClick={() => setPackageId(item.id)} className={`relative min-h-24 rounded-2xl border p-3 text-left transition ${packageId === item.id ? "border-[#b9ff35] bg-[#b9ff35]/10 shadow-[inset_0_0_0_1px_rgba(185,255,53,.2)]" : "border-white/[0.09] bg-white/[0.025] hover:border-white/20"}`}>{item.note && <span className="absolute right-2 top-2 rounded-full bg-[#b9ff35] px-2 py-0.5 text-[8px] font-black uppercase text-[#091006]">{item.note}</span>}<strong className="block pr-8 text-xs leading-5">{item.label}</strong><span className="mt-2 block text-[11px] font-bold text-[#cfff72]">{formatRupiah(item.price)}</span></button>)}</div></section>

            <section className="panel p-5 sm:p-6"><div className="mb-5 flex items-center gap-3"><span className="grid size-7 place-items-center rounded-lg bg-[#b9ff35] text-xs font-black text-[#091006]">3</span><div><h2 className="font-bold">Pembayaran & kontak</h2><p className="mt-0.5 text-[11px] text-white/35">QRIS melalui Midtrans akan diaktifkan nanti.</p></div></div><div className="rounded-2xl border border-[#b9ff35]/35 bg-[#b9ff35]/[0.06] p-4"><div className="flex items-center gap-3"><span className="grid size-10 place-items-center rounded-xl bg-white text-black"><QrCode className="size-6" /></span><div className="flex-1"><strong className="text-sm">QRIS</strong><p className="mt-1 text-[10px] text-white/38">Bayar dari aplikasi bank atau dompet digital</p></div><CheckCircle2 className="size-5 text-[#b9ff35]" /></div></div><div className="mt-4"><label className="field-label" htmlFor="contact">Nomor WhatsApp</label><Input id="contact" inputMode="tel" value={contact} onChange={(event) => setContact(event.target.value)} placeholder="Contoh: 081234567890" className="h-11 rounded-xl border-white/10 bg-white/[0.035] text-white placeholder:text-white/22" /><p className="mt-2 flex items-start gap-1.5 text-[10px] leading-4 text-white/30"><Info className="mt-0.5 size-3 shrink-0" /> Invoice dan perubahan status akan dikirim ke nomor ini setelah integrasi aktif.</p></div></section>

            <label className="flex cursor-pointer items-start gap-3 rounded-2xl border border-white/[0.08] bg-white/[0.02] p-4 text-xs leading-5 text-white/45"><input type="checkbox" checked={agreed} onChange={(event) => setAgreed(event.target.checked)} className="mt-1 size-4 accent-[#b9ff35]" /><span>Saya sudah memeriksa data tujuan dan menyetujui <Link href="/terms" className="font-semibold text-[#cfff72] hover:underline">syarat transaksi</Link>.</span></label>
            {error && <div className="flex items-start gap-2 rounded-xl border border-red-400/20 bg-red-400/[0.07] p-3 text-xs leading-5 text-red-200"><AlertCircle className="mt-0.5 size-4 shrink-0" />{error}</div>}
            <Button type="submit" className="h-12 w-full rounded-xl bg-[#b9ff35] font-black text-[#091006] hover:bg-[#d0ff75] lg:hidden">Buat pesanan demo</Button>
          </form>

          <aside className="panel p-5 lg:sticky lg:top-28">
            <div className="flex items-center justify-between"><h2 className="font-bold">Ringkasan pesanan</h2><span className="rounded-full bg-amber-400/10 px-2.5 py-1 text-[9px] font-black uppercase text-amber-300">Demo</span></div>
            <div className="my-5 h-px bg-white/[0.08]" />
            <dl className="space-y-3 text-xs"><div className="flex justify-between gap-4"><dt className="text-white/38">Produk</dt><dd className="text-right font-semibold">{product.name}</dd></div><div className="flex justify-between gap-4"><dt className="text-white/38">Nominal</dt><dd className="text-right font-semibold">{selectedPackage?.label ?? "Belum dipilih"}</dd></div><div className="flex justify-between gap-4"><dt className="text-white/38">Harga</dt><dd>{formatRupiah(subtotal)}</dd></div><div className="flex justify-between gap-4"><dt className="text-white/38">Estimasi biaya admin</dt><dd>{formatRupiah(adminFee)}</dd></div></dl>
            <div className="my-5 h-px bg-white/[0.08]" />
            <div className="flex items-end justify-between"><span className="text-sm font-bold">Total</span><strong className="text-xl font-black text-[#b9ff35]">{formatRupiah(total)}</strong></div>
            <p className="mt-3 rounded-xl bg-white/[0.035] p-3 text-[9px] leading-4 text-white/30">Biaya admin di atas hanya simulasi 0,7%. Nilai final harus mengikuti persetujuan dan perhitungan resmi Midtrans saat API dipasang.</p>
            <Button type="button" onClick={(event) => submitOrder(event as unknown as FormEvent)} className="mt-5 hidden h-12 w-full rounded-xl bg-[#b9ff35] font-black text-[#091006] hover:bg-[#d0ff75] lg:flex"><LockKeyhole className="mr-2 size-4" />Buat pesanan demo</Button>
            <div className="mt-4 flex items-center justify-center gap-4 text-[9px] text-white/30"><span className="inline-flex items-center gap-1"><ShieldCheck className="size-3" /> Data aman</span><span className="inline-flex items-center gap-1"><CreditCard className="size-3" /> QRIS</span></div>
            {created && <div className="mt-5 rounded-2xl border border-[#b9ff35]/30 bg-[#b9ff35]/[0.08] p-4"><BadgeCheck className="size-6 text-[#b9ff35]" /><h3 className="mt-3 text-sm font-black">Simulasi berhasil</h3><p className="mt-1 text-[11px] leading-5 text-white/45">Invoice demo dibuat. Tidak ada pembayaran atau pesanan asli yang dikirim.</p><Link href="/track" className="mt-3 inline-block text-xs font-bold text-[#cfff72] hover:underline">Lihat contoh status →</Link></div>}
          </aside>
        </div>
      </main>
    </StoreLayout>
  );
}
