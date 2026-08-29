"use client";

import Link from "next/link";
import { BarChart3, Box, CircleDollarSign, FileQuestion, LayoutDashboard, PackageCheck, Plus, ReceiptText, Settings, ShoppingBag, TicketPercent, TrendingUp, Users } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs";
import { AdminProductManager } from "@/components/admin-product-manager";
import { AdminOrderManager } from "@/components/admin-order-manager";
import { AdminVoucherManager } from "@/components/admin-voucher-manager";
import { StoreLayout } from "@/components/store-layout";

const nav = [
  ["overview", "Ringkasan", LayoutDashboard], ["orders", "Pesanan", ReceiptText], ["products", "Produk", Box],
  ["vouchers", "Voucher", TicketPercent], ["content", "Konten", FileQuestion], ["users", "Pelanggan", Users],
  ["reports", "Laporan", BarChart3], ["settings", "Pengaturan", Settings],
] as const;

export default function AdminPage() {
  return (
    <StoreLayout>
      <main className="mx-auto min-h-[75vh] max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
        <div className="mb-7 flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between"><div><div className="flex items-center gap-2"><p className="eyebrow !mb-0">Panel pengelola</p><span className="rounded-full bg-[#b9ff35]/10 px-2 py-0.5 text-[8px] font-black uppercase text-[#d8ff8d]">D1 + Access</span></div><h1 className="mt-2 text-3xl font-black tracking-[-0.04em] sm:text-4xl">Admin LFAMILIA STORE</h1><p className="mt-2 text-xs text-white/35">Kelola produk API, antrean manual, stok kode otomatis, dan pesanan iPaymu.</p></div><Button asChild variant="outline" className="w-fit rounded-xl border-white/10 bg-white/[0.035] text-white hover:bg-white/[0.08] hover:text-white"><Link href="/"><ShoppingBag className="mr-2 size-4" />Lihat toko</Link></Button></div>
        <Tabs defaultValue="overview" className="grid items-start gap-5 lg:grid-cols-[220px_1fr]">
          <TabsList className="flex h-auto w-full gap-2 overflow-x-auto rounded-2xl border border-white/[0.08] bg-[#0d1019] p-2 lg:sticky lg:top-28 lg:flex-col lg:items-stretch">
            {nav.map(([value, label, Icon]) => <TabsTrigger key={value} value={value} className="h-10 shrink-0 justify-start rounded-xl px-3 text-xs text-white/42 data-[state=active]:bg-[#b9ff35] data-[state=active]:text-[#091006]"><Icon className="mr-2 size-4" />{label}</TabsTrigger>)}
          </TabsList>
          <div className="min-w-0">
            <TabsContent value="overview" className="mt-0 space-y-5"><div className="grid grid-cols-2 gap-3 xl:grid-cols-4">{[["Omzet hari ini", "Rp1.248.500", CircleDollarSign, "+12%"], ["Pesanan", "47", ReceiptText, "+8"], ["Berhasil", "42", PackageCheck, "89%"], ["Produk aktif", "128", Box, "+3"]].map(([label, value, Icon, change]) => { const IconComponent = Icon as typeof CircleDollarSign; return <div key={label as string} className="panel p-4"><div className="flex items-center justify-between"><span className="grid size-8 place-items-center rounded-lg bg-[#b9ff35]/10 text-[#b9ff35]"><IconComponent className="size-4" /></span><span className="text-[9px] font-bold text-[#b9ff35]">{change as string}</span></div><span className="mt-5 block text-[10px] text-white/32">{label as string}</span><strong className="mt-1 block text-xl font-black">{value as string}</strong></div>; })}</div><div className="grid gap-5 xl:grid-cols-[1.35fr_.65fr]"><div className="panel p-5"><div className="flex items-center justify-between"><div><h2 className="font-bold">Performa 7 hari</h2><p className="mt-1 text-[10px] text-white/30">Data ilustrasi</p></div><TrendingUp className="size-5 text-[#b9ff35]" /></div><div className="mt-8 flex h-40 items-end gap-2">{[38, 52, 45, 68, 61, 84, 76].map((height, index) => <div key={index} className="flex h-full flex-1 items-end"><div className="w-full rounded-t-md bg-gradient-to-t from-[#b9ff35]/25 to-[#b9ff35]" style={{ height: `${height}%` }} /></div>)}</div><div className="mt-3 flex justify-between text-[9px] text-white/25">{["Sen", "Sel", "Rab", "Kam", "Jum", "Sab", "Min"].map((day) => <span key={day}>{day}</span>)}</div></div><div className="panel p-5"><h2 className="font-bold">Status pesanan</h2><div className="mt-5 space-y-4">{[["Berhasil", 42, "bg-[#b9ff35]"], ["Diproses", 3, "bg-blue-400"], ["Menunggu", 1, "bg-amber-400"], ["Gagal", 1, "bg-red-400"]].map(([label, count, color]) => <div key={label as string}><div className="flex justify-between text-[10px]"><span className="text-white/40">{label as string}</span><strong>{count as number}</strong></div><div className="mt-2 h-1.5 rounded-full bg-white/[0.06]"><div className={`h-full rounded-full ${color as string}`} style={{ width: `${(count as number) * 2}%` }} /></div></div>)}</div></div></div></TabsContent>

            <TabsContent value="orders" className="mt-0"><AdminSection title="Daftar pesanan" description="Pantau pembayaran, provider otomatis, dan antrean manual."><AdminOrderManager /></AdminSection></TabsContent>
            <TabsContent value="products" className="mt-0"><AdminSection title="Katalog produk" description="Tambah, ubah, dan hapus produk serta harga yang tampil di toko."><AdminProductManager /></AdminSection></TabsContent>
            <TabsContent value="vouchers" className="mt-0"><AdminSection title="Stok kode otomatis" description="Impor kode REDFINGER atau lisensi lain, pantau stok, dan kirim otomatis setelah pembayaran."><AdminVoucherManager /></AdminSection></TabsContent>
            <TabsContent value="content" className="mt-0"><AdminSection title="Konten & FAQ" description="Ubah banner, pengumuman, dan bantuan dari satu tempat." action="Tambah konten"><div className="grid gap-3 sm:grid-cols-2">{[["Banner beranda", "1 konten aktif"], ["Pertanyaan umum", "6 artikel"], ["Syarat & ketentuan", "Diperbarui 28 Agu"], ["Pengumuman", "Mode demo aktif"]].map(([title, detail]) => <div key={title} className="rounded-xl border border-white/[0.08] bg-white/[0.02] p-4"><strong className="text-sm">{title}</strong><p className="mt-2 text-[10px] text-white/30">{detail}</p></div>)}</div></AdminSection></TabsContent>
            <TabsContent value="users" className="mt-0"><AdminSection title="Data pelanggan" description="Lihat akun dan aktivitas pelanggan."><DataTable headers={["Pelanggan", "Email", "Pesanan", "Total belanja", "Status"]} rows={[["Familia Demo", "familia@example.com", "12", "Rp843.000", "Aktif"], ["Aldy", "aldy@example.com", "7", "Rp412.500", "Aktif"], ["Pelanggan #003", "user003@example.com", "1", "Rp17.624", "Aktif"]]} /></AdminSection></TabsContent>
            <TabsContent value="reports" className="mt-0"><AdminSection title="Laporan bisnis" description="Ringkasan transaksi, laba, dan performa produk."><div className="grid gap-3 sm:grid-cols-3">{[["Omzet bulan ini", "Rp28.450.000"], ["Estimasi laba", "Rp2.845.000"], ["Pesanan sukses", "1.042"]].map(([label, value]) => <div key={label} className="rounded-xl border border-white/[0.08] bg-white/[0.02] p-4"><span className="text-[10px] text-white/30">{label}</span><strong className="mt-2 block text-lg">{value}</strong></div>)}</div><div className="mt-4 rounded-xl border border-dashed border-white/10 p-8 text-center text-xs text-white/30">Ekspor CSV/PDF akan tersedia setelah database aktif.</div></AdminSection></TabsContent>
            <TabsContent value="settings" className="mt-0"><AdminSection title="Pengaturan toko" description="Konfigurasi identitas toko dan integrasi."><div className="grid gap-4 sm:grid-cols-2"><SettingCard title="Database D1" status="Siap" text="Menyimpan katalog, pembayaran, antrean, serta stok kode terenkripsi." /><SettingCard title="Cloudflare Access" status="Wajib aktif" text="Membatasi /admin dan /api/admin hanya untuk email pemilik." /><SettingCard title="iPaymu" status="Butuh secret" text="Virtual Account bank, DANA, ShopeePay, QRIS, dan callback pembayaran." /><SettingCard title="DigiFlazz" status="Adapter siap" text="Provider otomatis 24 jam dengan SKU per nominal dan webhook aman." /><SettingCard title="VIPayment" status="Adapter siap" text="Provider alternatif resmi dengan API ID, API Key, dan webhook." /><SettingCard title="Stok kode internal" status="Adapter siap" text="Reservasi satu kode secara atomik lalu kirim melalui Resend dan WhatsApp Cloud API." /><SettingCard title="Provider lain" status="Bisa ditambah" text="Tambahkan adapter resmi baru tanpa mengubah checkout atau data pesanan." /></div></AdminSection></TabsContent>
          </div>
        </Tabs>
      </main>
    </StoreLayout>
  );
}

function AdminSection({ title, description, action, children }: { title: string; description: string; action?: string; children: React.ReactNode }) {
  return <section className="panel overflow-hidden"><div className="flex items-center justify-between gap-4 border-b border-white/[0.08] p-5"><div><h2 className="font-bold">{title}</h2><p className="mt-1 text-[10px] text-white/30">{description}</p></div>{action && <Button size="sm" className="rounded-lg bg-[#b9ff35] text-[10px] font-black text-[#091006] hover:bg-[#d0ff75]"><Plus className="mr-1 size-3.5" />{action}</Button>}</div><div className="p-4 sm:p-5">{children}</div></section>;
}

function DataTable({ headers, rows }: { headers: string[]; rows: string[][] }) {
  return <div className="overflow-x-auto rounded-xl border border-white/[0.08]"><Table><TableHeader><TableRow className="border-white/[0.08] hover:bg-transparent">{headers.map((header) => <TableHead key={header} className="h-10 whitespace-nowrap text-[10px] text-white/35">{header}</TableHead>)}</TableRow></TableHeader><TableBody>{rows.map((row, index) => <TableRow key={`${row[0]}-${index}`} className="border-white/[0.07] hover:bg-white/[0.025]">{row.map((cell, cellIndex) => <TableCell key={`${cell}-${cellIndex}`} className={`whitespace-nowrap text-xs ${cellIndex === 0 ? "font-semibold text-white" : "text-white/48"}`}>{cellIndex === row.length - 1 ? <span className="rounded-full bg-white/[0.06] px-2 py-1 text-[9px] font-bold">{cell}</span> : cell}</TableCell>)}</TableRow>)}</TableBody></Table></div>;
}

function SettingCard({ title, status, text }: { title: string; status: string; text: string }) {
  return <div className="rounded-xl border border-white/[0.08] bg-white/[0.02] p-4"><div className="flex items-start justify-between gap-3"><strong className="text-sm">{title}</strong><span className="rounded-full bg-white/[0.06] px-2 py-1 text-[8px] font-bold text-white/45">{status}</span></div><p className="mt-3 text-[10px] leading-5 text-white/30">{text}</p></div>;
}
