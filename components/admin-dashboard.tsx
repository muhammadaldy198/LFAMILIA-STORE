"use client";

import Link from "next/link";
import { useState, type ReactNode } from "react";
import type { LucideIcon } from "lucide-react";
import {
  BarChart3,
  Box,
  CreditCard,
  FileText,
  Headphones,
  LayoutDashboard,
  LogOut,
  Menu,
  PackageSearch,
  Settings,
  ShoppingBag,
  Sparkles,
  Users,
  X,
} from "lucide-react";
import { Button } from "@/components/ui/button";
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs";
import { AdminDigiflazzMonitor } from "@/components/admin-digiflazz-monitor";
import { AdminDokuDatabasePreparation } from "@/components/admin-doku-database-preparation";
import { AdminDigiflazzPricing } from "@/components/admin-digiflazz-pricing";
import { AdminExperienceManager } from "@/components/admin-experience-manager";
import { AdminIntegrationManager } from "@/components/admin-integration-manager";
import { AdminMemberManager } from "@/components/admin-member-manager";
import { AdminOrderManager } from "@/components/admin-order-manager";
import { AdminOverview } from "@/components/admin-overview";
import { AdminPaymentMethodManager } from "@/components/admin-payment-method-manager";
import { AdminPaymentPageManager } from "@/components/admin-payment-page-manager";
import { AdminProductManager } from "@/components/admin-product-manager";
import { AdminPromotionManager } from "@/components/admin-promotion-manager";
import { AdminReportsCenter } from "@/components/admin-reports-center";
import { AdminStorefrontManager } from "@/components/admin-storefront-manager";
import { AdminSupportManager } from "@/components/admin-support-manager";
import { AdminTeamManager } from "@/components/admin-team-manager";
import { AdminVoucherManager } from "@/components/admin-voucher-manager";
import { AdminWalletManager } from "@/components/admin-wallet-manager";

type Session = {
  id: number;
  email: string;
  name: string;
  role: "owner" | "staff";
};

type NavigationItem = {
  value: string;
  label: string;
  Icon: LucideIcon;
  ownerOnly?: boolean;
};

const navigation: NavigationItem[] = [
  { value: "overview", label: "Dashboard", Icon: LayoutDashboard },
  { value: "orders", label: "Pesanan", Icon: FileText },
  { value: "products", label: "Produk", Icon: Box },
  { value: "digiflazz", label: "Digiflazz", Icon: PackageSearch, ownerOnly: true },
  { value: "payments", label: "Pembayaran", Icon: CreditCard, ownerOnly: true },
  { value: "customers", label: "Pelanggan", Icon: Users, ownerOnly: true },
  { value: "promotions", label: "Promo", Icon: Sparkles, ownerOnly: true },
  { value: "content", label: "Konten", Icon: FileText },
  { value: "support", label: "Layanan Pelanggan", Icon: Headphones },
  { value: "reports", label: "Laporan", Icon: BarChart3, ownerOnly: true },
  { value: "team", label: "Staff & Akses", Icon: Users, ownerOnly: true },
  { value: "settings", label: "Pengaturan", Icon: Settings, ownerOnly: true },
];

export function AdminDashboard({
  expectedRole,
  initialSession,
}: {
  expectedRole: "owner" | "staff";
  initialSession: Session;
}) {
  const [menuOpen, setMenuOpen] = useState(false);
  const [activeTab, setActiveTab] = useState("overview");
  const isOwner = initialSession.role === "owner";
  const visibleNavigation = navigation.filter((item) => isOwner || !item.ownerOnly);
  const activeLabel = visibleNavigation.find((item) => item.value === activeTab)?.label ?? "Dashboard";
  const logoutPath = expectedRole === "owner" ? "/admin/panel/auth/logout" : "/staff/panel/auth/logout";

  function selectTab(value: string) {
    setActiveTab(value);
    setMenuOpen(false);
  }

  return (
    <div className="admin-v3 min-h-screen bg-[#f4f7fb] text-slate-950">
      <header className="sticky top-0 z-30 border-b border-slate-200 bg-white/95 backdrop-blur">
        <div className="mx-auto flex h-14 max-w-[1600px] items-center gap-3 px-3 sm:px-5">
          <Button
            type="button"
            variant="outline"
            size="icon"
            onClick={() => setMenuOpen(true)}
            className="border-slate-200 bg-white text-slate-700 hover:bg-slate-50 lg:hidden"
            aria-label="Buka navigasi panel"
          >
            <Menu className="size-4" />
          </Button>

          <button type="button" onClick={() => selectTab("overview")} className="flex min-w-0 items-center gap-2 text-left">
            <span className="grid size-8 shrink-0 place-items-center rounded-lg bg-[#155eef] text-[11px] font-black tracking-[-0.08em] text-white">LF</span>
            <span className="hidden min-w-0 sm:block">
              <strong className="block truncate text-[11px] font-black tracking-[0.12em] text-slate-900">LFAMILIA</strong>
              <span className="block truncate text-[9px] text-slate-400">ADMIN CONTROL CENTER</span>
            </span>
          </button>

          <div className="hidden min-w-0 border-l border-slate-200 pl-3 md:block">
            <p className="truncate text-xs font-bold text-slate-900">{activeLabel}</p>
            <p className="truncate text-[9px] text-slate-400">{isOwner ? "Panel Pemilik" : "Panel Staff"} · {initialSession.name}</p>
          </div>

          <div className="ml-auto flex items-center gap-2">
            <span className="hidden rounded-md bg-blue-50 px-2 py-1 text-[8px] font-black uppercase tracking-[0.12em] text-blue-700 sm:inline-flex">
              {isOwner ? "Pemilik" : "Staff"}
            </span>
            <Button asChild variant="outline" size="sm" className="hidden border-slate-200 bg-white text-slate-700 hover:bg-slate-50 sm:inline-flex">
              <Link href="/"><ShoppingBag className="size-3.5" />Lihat toko</Link>
            </Button>
            <form action={logoutPath} method="post">
              <Button type="submit" variant="outline" size="sm" className="border-red-200 bg-white text-red-600 hover:bg-red-50">
                <LogOut className="size-3.5" /><span className="hidden sm:inline">Keluar</span>
              </Button>
            </form>
          </div>
        </div>
      </header>

      <Tabs value={activeTab} onValueChange={selectTab} className="mx-auto max-w-[1600px] gap-0 lg:grid lg:grid-cols-[230px_minmax(0,1fr)]">
        {menuOpen && (
          <>
            <button type="button" onClick={() => setMenuOpen(false)} className="fixed inset-0 z-40 bg-slate-950/65 lg:hidden" aria-label="Tutup navigasi panel" />
            <aside className="admin-v3-sidebar fixed inset-y-0 left-0 z-50 flex w-[86vw] max-w-[300px] flex-col bg-[#101828] text-white shadow-2xl lg:hidden">
              <div className="flex h-14 items-center justify-between border-b border-white/10 px-3">
                <div className="flex items-center gap-2">
                  <span className="grid size-8 place-items-center rounded-lg bg-[#155eef] text-[11px] font-black text-white">LF</span>
                  <div><strong className="block text-[11px] tracking-[0.12em]">LFAMILIA</strong><span className="text-[8px] text-white/45">ADMIN PANEL</span></div>
                </div>
                <Button type="button" variant="ghost" size="icon" onClick={() => setMenuOpen(false)} className="text-white/70 hover:bg-white/10 hover:text-white"><X className="size-4" /></Button>
              </div>
              <div className="flex-1 overflow-y-auto p-2"><PanelNavigation items={visibleNavigation} activeTab={activeTab} mobile onSelect={selectTab} /></div>
              <div className="border-t border-white/10 p-3"><p className="truncate text-xs font-bold">{initialSession.name}</p><p className="mt-0.5 truncate text-[9px] text-white/40">{initialSession.email}</p></div>
            </aside>
          </>
        )}

        <aside className="admin-v3-sidebar hidden min-h-[calc(100vh-3.5rem)] bg-[#101828] text-white lg:block">
          <div className="sticky top-14 p-2">
            <div className="mb-2 rounded-lg border border-white/10 bg-white/[0.04] px-3 py-2.5">
              <p className="text-[10px] font-black tracking-[0.12em]">LFAMILIA STORE</p>
              <p className="mt-0.5 text-[9px] text-white/40">{isOwner ? "Pemilik · akses penuh" : "Staff · operasional"}</p>
            </div>
            <TabsList className="h-auto w-full flex-col items-stretch gap-0.5 bg-transparent p-0">
              <PanelNavigation items={visibleNavigation} activeTab={activeTab} onSelect={selectTab} />
            </TabsList>
          </div>
        </aside>

        <main className="admin-v3-content min-w-0 p-3 sm:p-5">
          <TabsContent value="overview" className="mt-0"><AdminOverview onNavigate={selectTab} /></TabsContent>

          <TabsContent value="orders" className="mt-0">
            <AdminSection title="Pesanan" description="Semua status pesanan, pembayaran, fulfillment, refund, dan order manual dalam satu tempat.">
              <AdminOrderManager />
            </AdminSection>
          </TabsContent>

          <TabsContent value="products" className="mt-0">
            <AdminSection title="Produk" description="Kelola katalog, nominal, gambar nominal, section pemisah, posisi nominal, harga, dan fulfillment.">
              <AdminProductManager />
            </AdminSection>
          </TabsContent>

          {isOwner && (
            <TabsContent value="digiflazz" className="mt-0 space-y-4">
              <AdminSection title="Digiflazz" description="Satu pusat untuk credential, price list, sinkronisasi harga, status seller, SKU, webhook, dan monitoring.">
                <AdminIntegrationManager view="providers" providerFilter={["digiflazz"]} />
              </AdminSection>
              <AdminSection title="Sinkronisasi harga" description="Sinkronkan harga modal Digiflazz dan hitung harga jual berdasarkan margin nominal.">
                <AdminDigiflazzPricing />
              </AdminSection>
              <AdminSection title="Monitoring SKU" description="Pantau seller OFF, stok, cut-off, kenaikan harga, dan SKU yang perlu perhatian.">
                <AdminDigiflazzMonitor />
              </AdminSection>
            </TabsContent>
          )}

          {isOwner && (
            <TabsContent value="payments" className="mt-0 space-y-4">
              <AdminSection title="Persiapan Database DOKU" description="Periksa dan siapkan kolom DOKU satu kali sebelum gateway diaktifkan. Proses ini tidak menghapus data toko.">
                <AdminDokuDatabasePreparation />
              </AdminSection>
              <AdminSection title="DOKU Direct API" description="DOKU adalah satu-satunya gateway checkout eksternal. Kelola Sandbox/Production dan credential terenkripsi di sini.">
                <AdminIntegrationManager view="providers" providerFilter={["doku"]} />
              </AdminSection>
              <AdminSection title="Aktivasi pembayaran" description="Aktifkan DOKU untuk checkout dan top up wallet pelanggan.">
                <AdminWalletManager view="checkout" />
              </AdminSection>
              <AdminSection title="Metode pembayaran" description="Kelola QRIS, e-wallet, dan Virtual Account yang memang aktif pada akun DOKU.">
                <AdminPaymentMethodManager />
              </AdminSection>
              <AdminSection title="Halaman pembayaran" description="Atur tampilan halaman pembayaran LFAMILIA tanpa menyerahkan UI checkout ke gateway.">
                <AdminPaymentPageManager />
              </AdminSection>
            </TabsContent>
          )}

          {isOwner && (
            <TabsContent value="customers" className="mt-0 space-y-4">
              <AdminSection title="Pelanggan" description="Akun pelanggan, tier, total transaksi, saldo, dan aktivitas dalam satu pusat.">
                <AdminMemberManager view="customers" />
              </AdminSection>
              <AdminSection title="Saldo & top up" description="Pantau top up DOKU/manual dan perubahan saldo pelanggan.">
                <AdminWalletManager view="topups" />
              </AdminSection>
            </TabsContent>
          )}

          {isOwner && (
            <TabsContent value="promotions" className="mt-0 space-y-4">
              <AdminSection title="Promo" description="Voucher diskon dan promo harga terjadwal dikelola dari satu menu.">
                <AdminPromotionManager role="owner" />
              </AdminSection>
              <AdminSection title="Stok kode digital" description="Kelola stok voucher internal yang dikirim setelah pembayaran berhasil.">
                <AdminVoucherManager />
              </AdminSection>
            </TabsContent>
          )}

          <TabsContent value="content" className="mt-0 space-y-4">
            <AdminSection title="Identitas & struktur toko" description="Logo, kontak, kategori, FAQ, dan informasi dasar storefront.">
              <AdminStorefrontManager role={initialSession.role} />
            </AdminSection>
            <AdminSection title="Konten website" description="Banner, pop-up, berita, ulasan, dan konten pengalaman pelanggan.">
              <AdminExperienceManager role={initialSession.role} />
            </AdminSection>
          </TabsContent>

          <TabsContent value="support" className="mt-0">
            <AdminSection title="Layanan Pelanggan" description="Tiket bantuan, komplain, refund, dan tindak lanjut pelanggan dalam satu antrean.">
              <AdminSupportManager />
            </AdminSection>
          </TabsContent>

          {isOwner && (
            <TabsContent value="reports" className="mt-0">
              <AdminSection title="Laporan" description="Ringkasan penjualan, omzet, performa produk, pelanggan, dan transaksi berdasarkan periode.">
                <AdminReportsCenter />
              </AdminSection>
            </TabsContent>
          )}

          {isOwner && (
            <TabsContent value="team" className="mt-0">
              <AdminSection title="Staff & Akses" description="Kelola akun staff, role, akses, dan kredensial panel operasional.">
                <AdminTeamManager />
              </AdminSection>
            </TabsContent>
          )}

          {isOwner && (
            <TabsContent value="settings" className="mt-0 space-y-4">
              <AdminSection title="Pengaturan Sistem" description="Integrasi pendukung yang bukan provider transaksi atau payment gateway.">
                <AdminIntegrationManager view="providers" providerFilter={["melostore", "resend", "security"]} />
              </AdminSection>
              <AdminSection title="VPS Relay Digiflazz" description="Opsional: gunakan relay IP statis hanya jika koneksi Digiflazz membutuhkannya. DOKU tidak menggunakan relay.">
                <AdminIntegrationManager view="relay" />
              </AdminSection>
            </TabsContent>
          )}
        </main>
      </Tabs>
    </div>
  );
}

function PanelNavigation({
  items,
  activeTab,
  onSelect,
  mobile = false,
}: {
  items: NavigationItem[];
  activeTab: string;
  onSelect: (value: string) => void;
  mobile?: boolean;
}) {
  if (mobile) {
    return (
      <nav className="space-y-0.5" aria-label="Navigasi panel mobile">
        {items.map(({ value, label, Icon }) => {
          const active = value === activeTab;
          return (
            <button key={value} type="button" onClick={() => onSelect(value)} className={"flex h-10 w-full items-center rounded-lg px-3 text-left text-[11px] transition " + (active ? "bg-[#155eef] font-bold text-white" : "text-white/62 hover:bg-white/[0.07] hover:text-white")}>
              <Icon className="mr-2.5 size-3.5" /><span className="truncate">{label}</span>
            </button>
          );
        })}
      </nav>
    );
  }
  return (
    <>
      {items.map(({ value, label, Icon }) => (
        <TabsTrigger key={value} value={value} onClick={() => onSelect(value)} className="flex h-9 w-full justify-start rounded-lg px-3 text-left text-[11px] font-medium text-white/58 hover:bg-white/[0.07] hover:text-white data-[state=active]:bg-[#155eef] data-[state=active]:font-bold data-[state=active]:text-white">
          <Icon className="mr-2.5 size-3.5" /><span className="truncate">{label}</span>
        </TabsTrigger>
      ))}
    </>
  );
}

function AdminSection({ title, description, children }: { title: string; description: string; children: ReactNode }) {
  return (
    <section className="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
      <div className="border-b border-slate-200 px-4 py-3">
        <h2 className="text-sm font-bold text-slate-900">{title}</h2>
        <p className="mt-0.5 text-[10px] leading-4 text-slate-500">{description}</p>
      </div>
      <div className="p-3 sm:p-4">{children}</div>
    </section>
  );
}
