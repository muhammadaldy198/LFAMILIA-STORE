"use client";

import { useEffect, useRef, useState, type FormEvent, type ReactNode } from "react";
import type { LucideIcon } from "lucide-react";
import {
  BarChart3,
  Box,
  ChevronDown,
  CreditCard,
  FileText,
  Headphones,
  LayoutDashboard,
  LifeBuoy,
  LogOut,
  Menu,
  PackageSearch,
  Search,
  Settings,
  Sparkles,
  Users,
  X,
} from "lucide-react";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs";
import { AdminBrandLogo } from "@/components/admin-brand-logo";
import { AdminNotifications } from "@/components/admin-notifications";
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
  const [globalSearch, setGlobalSearch] = useState("");
  const searchRef = useRef<HTMLInputElement>(null);
  const isOwner = initialSession.role === "owner";
  const visibleNavigation = navigation.filter((item) => isOwner || !item.ownerOnly);
  const logoutPath = expectedRole === "owner" ? "/admin/panel/auth/logout" : "/staff/panel/auth/logout";

  useEffect(() => {
    function handleShortcut(event: KeyboardEvent) {
      if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === "k") {
        event.preventDefault();
        searchRef.current?.focus();
      }
    }
    window.addEventListener("keydown", handleShortcut);
    return () => window.removeEventListener("keydown", handleShortcut);
  }, []);

  function selectTab(value: string) {
    setActiveTab(value);
    setMenuOpen(false);
  }

  function submitGlobalSearch(event: FormEvent) {
    event.preventDefault();
    const query = globalSearch.trim().toLowerCase();
    if (!query) return;
    if (query.includes("produk") || query.includes("sku")) selectTab("products");
    else if (query.includes("pelanggan") || query.includes("customer") || query.includes("member")) selectTab("customers");
    else selectTab("orders");
  }

  return (
    <Tabs value={activeTab} onValueChange={selectTab} className="admin-reference min-h-screen bg-[#f6f8fc] text-[#172033] lg:grid lg:grid-cols-[190px_minmax(0,1fr)]">
      {menuOpen && (
        <>
          <button
            type="button"
            onClick={() => setMenuOpen(false)}
            className="fixed inset-0 z-40 bg-slate-950/35 lg:hidden"
            aria-label="Tutup navigasi panel"
          />
          <aside className="fixed inset-y-0 left-0 z-50 flex w-[82vw] max-w-[250px] flex-col border-r border-[#e7ebf2] bg-white shadow-2xl lg:hidden">
            <Brand onClick={() => selectTab("overview")} />
            <div className="flex-1 overflow-y-auto px-3 py-3">
              <PanelNavigation items={visibleNavigation} activeTab={activeTab} mobile onSelect={selectTab} />
            </div>
            <SidebarHelp />
            <div className="border-t border-[#edf0f5] p-3">
              <div className="flex items-center gap-2.5">
                <span className="grid size-8 shrink-0 place-items-center rounded-full bg-[#155eef] text-[10px] font-black text-white">
                  {initialSession.name.trim().charAt(0).toUpperCase() || "A"}
                </span>
                <div className="min-w-0">
                  <p className="truncate text-[11px] font-bold text-[#1e293b]">{initialSession.name}</p>
                  <p className="truncate text-[9px] text-[#94a3b8]">{isOwner ? "Super Admin" : "Staff"}</p>
                </div>
                <Button type="button" variant="ghost" size="icon-sm" onClick={() => setMenuOpen(false)} className="ml-auto text-[#64748b]">
                  <X className="size-4" />
                </Button>
              </div>
            </div>
          </aside>
        </>
      )}

      <aside className="hidden min-h-screen border-r border-[#e7ebf2] bg-white lg:flex lg:flex-col">
        <Brand onClick={() => selectTab("overview")} />
        <div className="flex-1 px-3 py-3">
          <TabsList className="h-auto w-full flex-col items-stretch gap-1 bg-transparent p-0">
            <PanelNavigation items={visibleNavigation} activeTab={activeTab} onSelect={selectTab} />
          </TabsList>
        </div>
        <SidebarHelp />
      </aside>

      <div className="min-w-0">
        <header className="sticky top-0 z-30 border-b border-[#e8ecf3] bg-white/95 backdrop-blur">
          <div className="flex h-[54px] items-center gap-3 px-3 sm:px-5">
            <Button
              type="button"
              variant="outline"
              size="icon"
              onClick={() => setMenuOpen(true)}
              className="size-9 border-[#e2e8f0] bg-white text-[#475569] lg:hidden"
              aria-label="Buka navigasi"
            >
              <Menu className="size-4" />
            </Button>

            <form onSubmit={submitGlobalSearch} className="relative w-full max-w-[420px]">
              <Search className="absolute left-3 top-1/2 size-3.5 -translate-y-1/2 text-[#94a3b8]" />
              <Input
                ref={searchRef}
                value={globalSearch}
                onChange={(event) => setGlobalSearch(event.target.value)}
                placeholder="Cari pesanan, produk, atau pelanggan..."
                className="h-8 rounded-md border-[#e2e8f0] bg-[#f8fafc] pl-8 pr-12 text-[10px] text-[#334155] shadow-none placeholder:text-[#94a3b8]"
              />
              <kbd className="pointer-events-none absolute right-2 top-1/2 -translate-y-1/2 rounded border border-[#dbe2eb] bg-white px-1.5 py-0.5 text-[8px] font-semibold text-[#94a3b8]">
                Ctrl K
              </kbd>
            </form>

            <div className="ml-auto flex items-center gap-2">
              <AdminNotifications
                sessionId={initialSession.id}
                isOwner={isOwner}
                onNavigate={selectTab}
              />

              <div className="hidden items-center gap-2 sm:flex">
                <span className="grid size-8 place-items-center rounded-full bg-[#155eef] text-[10px] font-black text-white">
                  {initialSession.name.trim().charAt(0).toUpperCase() || "A"}
                </span>
                <div className="min-w-0 leading-tight">
                  <p className="max-w-28 truncate text-[10px] font-bold text-[#1e293b]">{initialSession.name}</p>
                  <p className="text-[8px] text-[#94a3b8]">{isOwner ? "Super Admin" : "Staff"}</p>
                </div>
                <ChevronDown className="size-3.5 text-[#94a3b8]" />
              </div>

              <form action={logoutPath} method="post">
                <Button type="submit" variant="ghost" size="icon-sm" className="text-[#94a3b8] hover:bg-red-50 hover:text-red-600" aria-label="Keluar">
                  <LogOut className="size-3.5" />
                </Button>
              </form>
            </div>
          </div>
        </header>

        <main className="admin-v3-content min-w-0 p-3 sm:p-5 lg:p-5">
          <div className="mx-auto max-w-[1450px]">
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
                <AdminSection title="Digiflazz" description="Credential, price list, sinkronisasi harga, status seller, SKU, webhook, dan monitoring.">
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
                <AdminSection title="Persiapan Database DOKU" description="Periksa dan siapkan kolom DOKU satu kali sebelum gateway diaktifkan.">
                  <AdminDokuDatabasePreparation />
                </AdminSection>
                <AdminSection title="DOKU Direct API" description="DOKU adalah gateway checkout eksternal LFAMILIA.">
                  <AdminIntegrationManager view="providers" providerFilter={["doku"]} />
                </AdminSection>
                <AdminSection title="Aktivasi pembayaran" description="Aktifkan DOKU untuk checkout dan top up wallet pelanggan.">
                  <AdminWalletManager view="checkout" />
                </AdminSection>
                <AdminSection title="Metode pembayaran" description="Kelola QRIS, e-wallet, dan Virtual Account yang aktif pada akun DOKU.">
                  <AdminPaymentMethodManager />
                </AdminSection>
                <AdminSection title="Halaman pembayaran" description="Atur tampilan halaman pembayaran LFAMILIA.">
                  <AdminPaymentPageManager />
                </AdminSection>
              </TabsContent>
            )}

            {isOwner && (
              <TabsContent value="customers" className="mt-0 space-y-4">
                <AdminSection title="Pelanggan" description="Akun pelanggan, tier, transaksi, saldo, dan aktivitas.">
                  <AdminMemberManager view="customers" />
                </AdminSection>
                <AdminSection title="Saldo & top up" description="Pantau top up DOKU/manual dan perubahan saldo pelanggan.">
                  <AdminWalletManager view="topups" />
                </AdminSection>
              </TabsContent>
            )}

            {isOwner && (
              <TabsContent value="promotions" className="mt-0 space-y-4">
                <AdminSection title="Promo" description="Voucher diskon dan promo harga terjadwal.">
                  <AdminPromotionManager role="owner" />
                </AdminSection>
                <AdminSection title="Stok kode digital" description="Kelola stok voucher internal.">
                  <AdminVoucherManager />
                </AdminSection>
              </TabsContent>
            )}

            <TabsContent value="content" className="mt-0 space-y-4">
              <AdminSection title="Identitas & struktur toko" description="Logo, kontak, kategori, FAQ, dan informasi dasar storefront.">
                <AdminStorefrontManager role={initialSession.role} />
              </AdminSection>
              <AdminSection title="Konten website" description="Banner, pop-up, berita, ulasan, dan konten pelanggan.">
                <AdminExperienceManager role={initialSession.role} />
              </AdminSection>
            </TabsContent>

            <TabsContent value="support" className="mt-0">
              <AdminSection title="Layanan Pelanggan" description="Tiket bantuan, komplain, refund, dan tindak lanjut pelanggan.">
                <AdminSupportManager />
              </AdminSection>
            </TabsContent>

            {isOwner && (
              <TabsContent value="reports" className="mt-0">
                <AdminSection title="Laporan" description="Penjualan, omzet, performa produk, pelanggan, dan transaksi.">
                  <AdminReportsCenter />
                </AdminSection>
              </TabsContent>
            )}

            {isOwner && (
              <TabsContent value="team" className="mt-0">
                <AdminSection title="Staff & Akses" description="Kelola akun staff, role, dan akses panel.">
                  <AdminTeamManager />
                </AdminSection>
              </TabsContent>
            )}

            {isOwner && (
              <TabsContent value="settings" className="mt-0 space-y-4">
                <AdminSection title="Pengaturan Sistem" description="Integrasi pendukung yang bukan provider transaksi atau payment gateway.">
                  <AdminIntegrationManager view="providers" providerFilter={["melostore", "resend", "security"]} />
                </AdminSection>
                <AdminSection title="VPS Relay Digiflazz" description="Opsional: relay IP statis hanya untuk Digiflazz.">
                  <AdminIntegrationManager view="relay" />
                </AdminSection>
              </TabsContent>
            )}
          </div>
        </main>
      </div>
    </Tabs>
  );
}

function Brand({ onClick }: { onClick(): void }) {
  return (
    <button type="button" onClick={onClick} className="flex h-[54px] w-full items-center gap-2.5 border-b border-[#edf0f5] px-4 text-left">
      <AdminBrandLogo primarySrc="/lfamilia-admin-logo.webp" />
      <span className="min-w-0">
        <strong className="block truncate text-[13px] font-black tracking-[-0.03em] text-[#172033]">LFAMILIA</strong>
        <span className="block truncate text-[6px] font-bold uppercase tracking-[0.1em] text-[#94a3b8]">Top Up & Digital Service</span>
      </span>
    </button>
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
      <nav className="space-y-1" aria-label="Navigasi panel mobile">
        {items.map(({ value, label, Icon }) => {
          const active = value === activeTab;
          return (
            <button
              key={value}
              type="button"
              onClick={() => onSelect(value)}
              className={
                "flex h-9 w-full items-center rounded-md px-2.5 text-left text-[10px] font-semibold transition " +
                (active
                  ? "bg-[#edf4ff] text-[#155eef]"
                  : "text-[#526072] hover:bg-[#f7f9fc] hover:text-[#172033]")
              }
            >
              <Icon className="mr-2.5 size-3.5" />
              <span className="truncate">{label}</span>
            </button>
          );
        })}
      </nav>
    );
  }

  return (
    <>
      {items.map(({ value, label, Icon }) => (
        <TabsTrigger
          key={value}
          value={value}
          onClick={() => onSelect(value)}
          className="flex h-9 w-full justify-start rounded-md px-2.5 text-left text-[10px] font-semibold text-[#526072] hover:bg-[#f7f9fc] hover:text-[#172033] data-[state=active]:bg-[#edf4ff] data-[state=active]:text-[#155eef] data-[state=active]:shadow-none"
        >
          <Icon className="mr-2.5 size-3.5" />
          <span className="truncate">{label}</span>
        </TabsTrigger>
      ))}
    </>
  );
}

function SidebarHelp() {
  return (
    <div className="p-3">
      <button type="button" className="w-full rounded-lg border border-[#edf0f5] bg-[#fbfcfe] p-3 text-left transition hover:border-[#dfe5ee] hover:bg-white">
        <p className="text-[9px] font-semibold text-[#64748b]">Butuh bantuan?</p>
        <p className="mt-2 flex items-center gap-2 text-[9px] font-bold text-[#334155]">
          <span className="grid size-6 place-items-center rounded-md bg-white text-[#64748b] shadow-sm">
            <LifeBuoy className="size-3.5" />
          </span>
          Pusat Bantuan
        </p>
      </button>
    </div>
  );
}

function AdminSection({ title, description, children }: { title: string; description: string; children: ReactNode }) {
  return (
    <section className="overflow-hidden rounded-lg border border-[#e4e9f1] bg-white shadow-[0_1px_3px_rgba(15,23,42,0.03)]">
      <div className="border-b border-[#edf0f5] px-4 py-3">
        <h2 className="text-sm font-bold text-[#172033]">{title}</h2>
        <p className="mt-0.5 text-[9px] leading-4 text-[#94a3b8]">{description}</p>
      </div>
      <div className="p-3 sm:p-4">{children}</div>
    </section>
  );
}
