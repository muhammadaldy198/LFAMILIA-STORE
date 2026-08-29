"use client";

import { useEffect, useState } from "react";
import Link from "next/link";
import { Box, FileQuestion, Flame, LayoutDashboard, LoaderCircle, LockKeyhole, ReceiptText, Settings, ShieldCheck, ShoppingBag, TicketPercent, Users } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs";
import { AdminOrderManager } from "@/components/admin-order-manager";
import { AdminOverview } from "@/components/admin-overview";
import { AdminProductManager } from "@/components/admin-product-manager";
import { AdminPromotionManager } from "@/components/admin-promotion-manager";
import { AdminStorefrontManager } from "@/components/admin-storefront-manager";
import { AdminTeamManager } from "@/components/admin-team-manager";
import { AdminVoucherManager } from "@/components/admin-voucher-manager";
import { StoreLayout } from "@/components/store-layout";

type Session = { id: number | null; email: string; name: string; role: "owner" | "staff" };

const baseNav = [
  ["overview", "Ringkasan", LayoutDashboard], ["orders", "Pesanan", ReceiptText], ["products", "Produk", Box], ["content", "Konten", FileQuestion],
] as const;
const ownerNav = [
  ["promotions", "Promo", Flame], ["vouchers", "Stok kode", TicketPercent], ["team", "Tim admin", Users], ["settings", "Integrasi", Settings],
] as const;

export default function AdminPage() {
  const [session, setSession] = useState<Session | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");
  useEffect(() => { void fetch("/api/admin/session", { cache: "no-store" }).then(async (response) => { const data = await response.json(); if (!response.ok) throw new Error(data.error); setSession(data.session); }).catch((reason) => setError(reason instanceof Error ? reason.message : "Akses admin gagal diperiksa.")).finally(() => setLoading(false)); }, []);
  if (loading) return <StoreLayout><main className="mx-auto flex min-h-[70vh] max-w-7xl items-center justify-center px-4 text-xs text-white/40"><LoaderCircle className="mr-2 size-4 animate-spin" />Memeriksa akses admin…</main></StoreLayout>;
  if (!session) return <StoreLayout><main className="mx-auto grid min-h-[70vh] max-w-xl place-items-center px-4"><div className="panel w-full p-7 text-center"><LockKeyhole className="mx-auto size-8 text-amber-300" /><h1 className="mt-4 text-xl font-black">Akses admin ditolak</h1><p className="mt-3 text-sm leading-6 text-white/42">{error || "Email ini belum terdaftar sebagai Pemilik atau Staff aktif."}</p></div></main></StoreLayout>;
  const isOwner = session.role === "owner";
  const nav = isOwner ? [...baseNav, ...ownerNav] : baseNav;
  return <StoreLayout><main className="mx-auto min-h-[75vh] max-w-7xl px-4 py-8 sm:px-6 lg:px-8"><div className="mb-7 flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between"><div><div className="flex flex-wrap items-center gap-2"><p className="eyebrow !mb-0">Panel pengelola</p><span className={`rounded-full px-2 py-0.5 text-[8px] font-black uppercase ${isOwner ? "bg-[#b9ff35]/10 text-[#d8ff8d]" : "bg-blue-400/10 text-blue-300"}`}>{isOwner ? "Pemilik" : "Staff"}</span></div><h1 className="mt-2 text-3xl font-black tracking-[-0.04em] sm:text-4xl">Admin LFAMILIA STORE</h1><p className="mt-2 text-xs text-white/35">Masuk sebagai {session.name} • {session.email}</p></div><Button asChild variant="outline" className="w-fit rounded-xl border-white/10 bg-white/[0.035] text-white hover:bg-white/[0.08] hover:text-white"><Link href="/"><ShoppingBag className="mr-2 size-4" />Lihat toko</Link></Button></div><Tabs defaultValue="overview" className="grid items-start gap-5 lg:grid-cols-[220px_1fr]"><TabsList className="flex h-auto w-full gap-2 overflow-x-auto rounded-2xl border border-white/[0.08] bg-[#0d1019] p-2 lg:sticky lg:top-28 lg:flex-col lg:items-stretch">{nav.map(([value, label, Icon]) => <TabsTrigger key={value} value={value} className="h-10 shrink-0 justify-start rounded-xl px-3 text-xs text-white/42 data-[state=active]:bg-[#b9ff35] data-[state=active]:text-[#091006]"><Icon className="mr-2 size-4" />{label}</TabsTrigger>)}</TabsList><div className="min-w-0">
    <TabsContent value="overview" className="mt-0"><AdminOverview /></TabsContent>
    <TabsContent value="orders" className="mt-0"><AdminSection title="Daftar pesanan" description="Pantau pembayaran, provider otomatis, dan antrean manual."><AdminOrderManager /></AdminSection></TabsContent>
    <TabsContent value="products" className="mt-0"><AdminSection title="Katalog produk" description={isOwner ? "Kelola gambar, pop-up informasi, jam operasional, nominal, harga, dan SKU provider." : "Staff dapat memperbarui gambar, jam layanan, instruksi, dan pop-up. Harga serta provider hanya tersedia untuk Pemilik."}><AdminProductManager /></AdminSection></TabsContent>
    <TabsContent value="content" className="mt-0"><AdminSection title="Tampilan & konten" description="Kelola logo, banner Home, pengumuman, kategori, dan FAQ."><AdminStorefrontManager role={session.role} /></AdminSection></TabsContent>
    {isOwner && <><TabsContent value="promotions" className="mt-0"><AdminSection title="Voucher diskon & flash sale" description="Atur periode, harga promo, minimum pembelian, kuota, dan batas diskon."><AdminPromotionManager role="owner" /></AdminSection></TabsContent><TabsContent value="vouchers" className="mt-0"><AdminSection title="Stok kode otomatis" description="Kode digital terenkripsi, pengiriman, dan percobaan ulang hanya dapat diakses Pemilik."><AdminVoucherManager /></AdminSection></TabsContent><TabsContent value="team" className="mt-0"><AdminSection title="Pemilik & Staff" description="Atur dua tingkat akses tanpa menyimpan password di website."><AdminTeamManager /></AdminSection></TabsContent><TabsContent value="settings" className="mt-0"><AdminSection title="Integrasi rahasia" description="Secret pembayaran dan provider dikelola melalui Cloudflare agar tidak pernah tampil di browser."><div className="grid gap-4 sm:grid-cols-2"><SettingCard title="Cloudflare D1" status="Terhubung" text="Katalog, promo, tim, pesanan, dan stok kode tersimpan di database." /><SettingCard title="Cloudflare Access" status="Wajib" text="Melindungi /admin dan /api/admin sebelum permintaan mencapai aplikasi." /><SettingCard title="iPaymu" status="Secret Cloudflare" text="Virtual Account, dompet digital, QRIS, serta callback pembayaran." /><SettingCard title="DigiFlazz" status="Secret Cloudflare" text="Produk otomatis menggunakan SKU per nominal dan callback tervalidasi." /><SettingCard title="VIPayment" status="Secret Cloudflare" text="Provider alternatif resmi untuk produk otomatis." /><SettingCard title="Pengiriman kode" status="Khusus Pemilik" text="Resend dan WhatsApp Cloud API mengirim voucher tanpa memperlihatkan stok ke Staff." /></div><div className="mt-4 rounded-xl border border-amber-300/20 bg-amber-300/[0.06] p-4 text-[10px] leading-5 text-amber-100/60"><ShieldCheck className="mb-2 size-4 text-amber-300" />Secret tidak dapat diedit dari panel agar Staff, browser, dan kode frontend tidak pernah dapat membacanya. Perubahan secret dilakukan Pemilik melalui Cloudflare Worker Settings.</div></AdminSection></TabsContent></>}
  </div></Tabs></main></StoreLayout>;
}

function AdminSection({ title, description, children }: { title: string; description: string; children: React.ReactNode }) { return <section className="panel overflow-hidden"><div className="border-b border-white/[0.08] p-5"><h2 className="font-bold">{title}</h2><p className="mt-1 text-[10px] text-white/30">{description}</p></div><div className="p-4 sm:p-5">{children}</div></section>; }
function SettingCard({ title, status, text }: { title: string; status: string; text: string }) { return <div className="rounded-xl border border-white/[0.08] bg-white/[0.02] p-4"><div className="flex items-start justify-between gap-3"><strong className="text-sm">{title}</strong><span className="rounded-full bg-white/[0.06] px-2 py-1 text-[8px] font-bold text-white/45">{status}</span></div><p className="mt-3 text-[10px] leading-5 text-white/30">{text}</p></div>; }
