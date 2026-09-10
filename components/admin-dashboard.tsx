"use client";

import { useEffect, useRef, useState, type FormEvent, type ReactNode } from "react";
import type { LucideIcon } from "lucide-react";
import {
  BarChart3,
  Boxes,
  CreditCard,
  FileText,
  Gamepad2,
  Headphones,
  ImageIcon,
  LayoutDashboard,
  LifeBuoy,
  Newspaper,
  PackageSearch,
  Search,
  Settings,
  Sparkles,
  UserCog,
  Users,
} from "lucide-react";
import { Input } from "@/components/ui/input";
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs";
import { AdminAccountMenu } from "@/components/admin-account-menu";
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
  { value: "products", label: "Produk", Icon: Boxes },
  { value: "content", label: "Banner & Konten", Icon: ImageIcon },
  { value: "digiflazz", label: "Digiflazz", Icon: PackageSearch, ownerOnly: true },
  { value: "payments", label: "Pembayaran", Icon: CreditCard, ownerOnly: true },
  { value: "customers", label: "Pelanggan", Icon: Users, ownerOnly: true },
  { value: "promotions", label: "Promo", Icon: Sparkles, ownerOnly: true },
  { value: "site-content", label: "Konten", Icon: Newspaper },
  { value: "support", label: "Layanan Pelanggan", Icon: Headphones },
  { value: "reports", label: "Laporan", Icon: BarChart3, ownerOnly: true },
  { value: "team", label: "Staff & Admin Akses", Icon: UserCog, ownerOnly: true },
  { value: "settings", label: "Pengaturan", Icon: Settings, ownerOnly: true },
];

export function AdminDashboard({
  expectedRole,
  initialSession,
}: {
  expectedRole: "owner" | "staff";
  initialSession: Session;
}) {
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

  function submitGlobalSearch(event: FormEvent) {
    event.preventDefault();
    const query = globalSearch.trim().toLowerCase();
    if (!query) return;
    const target = visibleNavigation.find((item) => item.label.toLowerCase().includes(query));
    if (target) setActiveTab(target.value);
    else if (query.includes("sku") || query.includes("produk")) setActiveTab("products");
    else if (query.includes("pelanggan")) setActiveTab("customers");
    else setActiveTab("orders");
  }

  return (
    <Tabs
      value={activeTab}
      onValueChange={setActiveTab}
      className="admin-reference grid min-h-screen min-w-[1180px] grid-cols-[230px_minmax(0,1fr)] bg-[#f4f7fb] text-[#0f1f3d]"
    >
      <aside className="sticky top-0 flex h-screen flex-col overflow-hidden bg-[#112842] text-white shadow-[6px_0_24px_rgba(15,37,64,0.12)]">
        <Brand onClick={() => setActiveTab("overview")} />

        <div className="min-h-0 flex-1 overflow-y-auto px-2.5 py-3 scrollbar-none">
          <TabsList className="h-auto w-full flex-col items-stretch gap-1 bg-transparent p-0">
            {visibleNavigation.map(({ value, label, Icon }) => (
              <TabsTrigger
                key={value}
                value={value}
                className="flex h-10 w-full justify-start rounded-md px-3 text-left text-[12px] font-medium text-slate-200/90 transition hover:bg-white/[0.08] hover:text-white data-[state=active]:bg-[#1769e8] data-[state=active]:text-white data-[state=active]:shadow-[0_5px_16px_rgba(23,105,232,0.28)]"
              >
                <Icon className="mr-3 size-[17px] shrink-0" strokeWidth={1.9} />
                <span className="truncate">{label}</span>
              </TabsTrigger>
            ))}
          </TabsList>
        </div>

        <SidebarHelp />
        <div className="px-5 pb-5 pt-2 text-[9px] leading-4 text-slate-400">
          <p>© {new Date().getFullYear()} LFAMILIA</p>
          <p>Top Up Game Solution</p>
          <p>v1.0.0</p>
        </div>
      </aside>

      <div className="min-w-0">
        <header className="sticky top-0 z-30 flex h-[58px] items-center border-b border-[#e5eaf1] bg-white px-5 shadow-[0_1px_2px_rgba(15,23,42,0.02)]">
          <form onSubmit={submitGlobalSearch} className="relative w-full max-w-[550px]">
            <Search className="absolute left-3 top-1/2 size-4 -translate-y-1/2 text-[#7d8ba3]" />
            <Input
              ref={searchRef}
              value={globalSearch}
              onChange={(event) => setGlobalSearch(event.target.value)}
              placeholder="Cari menu, produk, pesanan, atau pelanggan..."
              className="h-9 rounded-md border-[#dfe5ed] bg-[#f8fafc] pl-9 pr-14 text-[11px] text-[#26364f] shadow-none placeholder:text-[#98a5b8] focus-visible:ring-[#1769e8]/30"
            />
            <kbd className="pointer-events-none absolute right-2.5 top-1/2 -translate-y-1/2 rounded border border-[#dfe5ed] bg-white px-1.5 py-0.5 text-[9px] font-semibold text-[#8b98aa]">
              Ctrl K
            </kbd>
          </form>

          <div className="ml-auto flex items-center gap-3">
            <AdminNotifications sessionId={initialSession.id} isOwner={isOwner} onNavigate={setActiveTab} />
            <span className="h-7 w-px bg-[#e7ebf1]" />
            <AdminAccountMenu session={initialSession} logoutPath={logoutPath} onNavigate={setActiveTab} />
          </div>
        </header>

        <main className="admin-v3-content min-w-0 p-5">
          <div className="mx-auto min-w-0 max-w-[1540px]">
            <TabsContent value="overview" className="mt-0"><AdminOverview onNavigate={setActiveTab} /></TabsContent>
            <TabsContent value="orders" className="mt-0"><AdminOrderManager /></TabsContent>
            <TabsContent value="products" className="mt-0"><AdminProductManager /></TabsContent>
            <TabsContent value="content" className="mt-0"><AdminExperienceManager role={initialSession.role} /></TabsContent>
            <TabsContent value="site-content" className="mt-0"><AdminExperienceManager role={initialSession.role} /></TabsContent>

            {isOwner && (
              <TabsContent value="digiflazz" className="mt-0 space-y-4">
                <AdminSection title="Digiflazz" description="Credential, sinkronisasi, SKU, saldo, dan status layanan.">
                  <AdminIntegrationManager view="providers" providerFilter={["digiflazz"]} />
                </AdminSection>
                <AdminSection title="Sinkronisasi Harga" description="Sinkronkan harga modal dan margin produk.">
                  <AdminDigiflazzPricing />
                </AdminSection>
                <AdminSection title="Monitoring SKU" description="Pantau status seller, stok, dan perubahan harga.">
                  <AdminDigiflazzMonitor />
                </AdminSection>
              </TabsContent>
            )}

            {isOwner && (
              <TabsContent value="payments" className="mt-0 space-y-4">
                <AdminSection title="Persiapan Database DOKU" description="Persiapan integrasi pembayaran."><AdminDokuDatabasePreparation /></AdminSection>
                <AdminSection title="DOKU Direct API" description="Pengaturan gateway pembayaran."><AdminIntegrationManager view="providers" providerFilter={["doku"]} /></AdminSection>
                <AdminSection title="Aktivasi Pembayaran" description="Atur checkout dan top up pelanggan."><AdminWalletManager view="checkout" /></AdminSection>
                <AdminSection title="Metode Pembayaran" description="Kelola QRIS, e-wallet, dan Virtual Account."><AdminPaymentMethodManager /></AdminSection>
                <AdminSection title="Halaman Pembayaran" description="Atur tampilan pembayaran LFAMILIA."><AdminPaymentPageManager /></AdminSection>
              </TabsContent>
            )}

            {isOwner && (
              <TabsContent value="customers" className="mt-0 space-y-4">
                <AdminSection title="Pelanggan" description="Kelola akun, tier, transaksi, dan aktivitas pelanggan."><AdminMemberManager view="customers" /></AdminSection>
                <AdminSection title="Saldo & Top Up" description="Pantau top up dan perubahan saldo."><AdminWalletManager view="topups" /></AdminSection>
              </TabsContent>
            )}

            {isOwner && (
              <TabsContent value="promotions" className="mt-0 space-y-4">
                <AdminSection title="Promo" description="Kelola voucher diskon dan promo terjadwal."><AdminPromotionManager role="owner" /></AdminSection>
                <AdminSection title="Stok Kode Digital" description="Kelola stok voucher internal."><AdminVoucherManager /></AdminSection>
              </TabsContent>
            )}

            <TabsContent value="support" className="mt-0"><AdminSection title="Layanan Pelanggan" description="Tiket bantuan, komplain, refund, dan tindak lanjut."><AdminSupportManager /></AdminSection></TabsContent>
            {isOwner && <TabsContent value="reports" className="mt-0"><AdminSection title="Laporan" description="Penjualan, omzet, performa produk, dan transaksi."><AdminReportsCenter /></AdminSection></TabsContent>}
            {isOwner && <TabsContent value="team" className="mt-0"><AdminSection title="Staff & Admin Akses" description="Kelola akun, role, permission, dan aktivitas tim."><AdminTeamManager /></AdminSection></TabsContent>}

            {isOwner && (
              <TabsContent value="settings" className="mt-0 space-y-4">
                <AdminSection title="Identitas & Struktur Toko" description="Profil toko, kontak, logo, kategori, dan kanal publik."><AdminStorefrontManager role={initialSession.role} /></AdminSection>
                <AdminSection title="Pengaturan Sistem" description="Integrasi pendukung dan keamanan sistem."><AdminIntegrationManager view="providers" providerFilter={["melostore", "resend", "security"]} /></AdminSection>
                <AdminSection title="VPS Relay Digiflazz" description="Konfigurasi relay untuk Digiflazz."><AdminIntegrationManager view="relay" /></AdminSection>
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
    <button type="button" onClick={onClick} className="flex h-[70px] w-full items-center gap-3 border-b border-white/[0.07] px-4 text-left">
      <span className="grid size-[42px] place-items-center rounded-full bg-gradient-to-br from-[#2483ff] to-[#0b56c8] shadow-[0_8px_22px_rgba(17,99,224,0.35)]">
        <Gamepad2 className="size-6 text-white" strokeWidth={2.2} />
      </span>
      <span className="min-w-0">
        <strong className="block truncate text-[14px] font-extrabold tracking-[-0.02em] text-white">LFAMILIA ADMIN</strong>
        <span className="mt-0.5 block truncate text-[8px] font-medium text-slate-400">Top Up Game Solution</span>
      </span>
    </button>
  );
}

function SidebarHelp() {
  return (
    <div className="px-4 pb-3">
      <div className="rounded-lg bg-white/[0.055] p-3">
        <div className="flex items-start gap-2.5">
          <span className="grid size-8 shrink-0 place-items-center rounded-full border border-white/20 text-white"><LifeBuoy className="size-4" /></span>
          <div>
            <p className="text-[10px] font-semibold text-white">Butuh bantuan?</p>
            <p className="mt-0.5 text-[8px] leading-3.5 text-slate-400">Tim kami siap membantu Anda 24/7.</p>
          </div>
        </div>
        <button type="button" className="mt-3 h-8 w-full rounded-md bg-white/[0.08] text-[9px] font-semibold text-white transition hover:bg-white/[0.13]">Pusat Bantuan</button>
      </div>
    </div>
  );
}

function AdminSection({ title, description, children }: { title: string; description: string; children: ReactNode }) {
  return (
    <section className="overflow-hidden rounded-lg border border-[#e1e6ed] bg-white shadow-[0_1px_3px_rgba(15,23,42,0.04)]">
      <div className="border-b border-[#edf0f4] px-4 py-3">
        <h2 className="text-sm font-bold text-[#14213a]">{title}</h2>
        <p className="mt-0.5 text-[9px] leading-4 text-[#8190a5]">{description}</p>
      </div>
      <div className="p-4">{children}</div>
    </section>
  );
}
