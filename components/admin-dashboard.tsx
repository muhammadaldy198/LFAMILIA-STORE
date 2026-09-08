"use client";

import Link from "next/link";
import { useState, type ReactNode } from "react";
import type { LucideIcon } from "lucide-react";
import {
  Box,
  CreditCard,
  FileQuestion,
  LayoutDashboard,
  LogOut,
  Menu,
  MessageCircleMore,
  ReceiptText,
  Settings,
  ShieldCheck,
  ShoppingBag,
  TicketPercent,
  Users,
  WalletCards,
  X,
} from "lucide-react";
import { Button } from "@/components/ui/button";
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs";
import { AdminDigiflazzPricing } from "@/components/admin-digiflazz-pricing";
import { AdminIntegrationManager } from "@/components/admin-integration-manager";
import { AdminExperienceManager } from "@/components/admin-experience-manager";
import { AdminMemberManager } from "@/components/admin-member-manager";
import { AdminOrderManager } from "@/components/admin-order-manager";
import { AdminOverview } from "@/components/admin-overview";
import { AdminPaymentMethodManager } from "@/components/admin-payment-method-manager";
import { AdminPaymentPageManager } from "@/components/admin-payment-page-manager";
import { AdminProductManager } from "@/components/admin-product-manager";
import { AdminPromotionManager } from "@/components/admin-promotion-manager";
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
};

type NavigationGroup = {
  label: string;
  items: NavigationItem[];
};

const baseNavigation: NavigationGroup[] = [
  {
    label: "Utama",
    items: [{ value: "overview", label: "Dashboard", Icon: LayoutDashboard }],
  },
  {
    label: "Operasional",
    items: [
      { value: "orders", label: "Pesanan realtime", Icon: ReceiptText },
      { value: "support", label: "Bantuan & refund", Icon: MessageCircleMore },
    ],
  },
  {
    label: "Katalog & situs",
    items: [
      { value: "products", label: "Produk & nominal", Icon: Box },
      { value: "content", label: "Konten website", Icon: FileQuestion },
    ],
  },
];

const ownerNavigation: NavigationGroup[] = [
  {
    label: "Kampanye & stok",
    items: [
      { value: "promotions", label: "Promo & diskon", Icon: TicketPercent },
      { value: "vouchers", label: "Stok kode digital", Icon: TicketPercent },
    ],
  },
  {
    label: "Keuangan",
    items: [
      { value: "payments", label: "Pembayaran", Icon: CreditCard },
      { value: "wallet", label: "Wallet pelanggan", Icon: WalletCards },
    ],
  },
  {
    label: "Pengguna",
    items: [
      { value: "members", label: "Member & privilege", Icon: ShieldCheck },
      { value: "team", label: "Staff & akses", Icon: Users },
    ],
  },
  {
    label: "Sistem",
    items: [{ value: "settings", label: "Integrasi & harga", Icon: Settings }],
  },
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
  const navGroups = isOwner ? [...baseNavigation, ...ownerNavigation] : baseNavigation;
  const activeItem = navGroups
    .flatMap((group) => group.items)
    .find((item) => item.value === activeTab);
  const activeLabel = activeItem?.label ?? "Dashboard";

  function selectTab(value: string) {
    setActiveTab(value);
    setMenuOpen(false);
  }

  const logoutPath = expectedRole === "owner" ? "/admin/panel/auth/logout" : "/staff/panel/auth/logout";

  return (
    <div className="min-h-screen bg-[#07090f] text-white">
      <header className="sticky top-0 z-30 border-b border-white/[0.08] bg-[#090c13]/95 backdrop-blur">
        <div className="mx-auto flex h-14 max-w-[1440px] items-center gap-3 px-3 sm:px-5">
          <Button
            type="button"
            variant="outline"
            size="icon"
            onClick={() => setMenuOpen(true)}
            className="border-white/10 bg-white/[0.03] text-white hover:bg-white/[0.08] hover:text-white lg:hidden"
            aria-label="Buka navigasi panel"
            aria-controls="admin-mobile-navigation"
            aria-expanded={menuOpen}
          >
            <Menu className="size-4" />
          </Button>

          <button
            type="button"
            onClick={() => selectTab("overview")}
            className="flex min-w-0 items-center gap-2 text-left"
            aria-label="Buka dashboard"
          >
            <span className="grid size-8 shrink-0 place-items-center rounded-md bg-[#b9ff35] text-[11px] font-black tracking-[-0.08em] text-[#091006]">
              LF
            </span>
            <span className="hidden min-w-0 sm:block">
              <strong className="block truncate text-[11px] font-black tracking-[0.15em]">LFAMILIA</strong>
              <span className="block truncate text-[9px] text-white/38">ADMIN CONTROL CENTER</span>
            </span>
          </button>

          <div className="hidden min-w-0 border-l border-white/[0.09] pl-3 md:block">
            <p className="truncate text-xs font-bold">{activeLabel}</p>
            <p className="truncate text-[9px] text-white/34">
              {isOwner ? "Panel Pemilik" : "Panel Staff"} · {initialSession.name}
            </p>
          </div>

          <div className="ml-auto flex items-center gap-2">
            <span
              className={
                "hidden rounded-md px-2 py-1 text-[8px] font-black uppercase tracking-[0.14em] sm:inline-flex " +
                (isOwner ? "bg-[#b9ff35]/10 text-[#d8ff8d]" : "bg-blue-400/10 text-blue-200")
              }
            >
              {isOwner ? "Pemilik" : "Staff"}
            </span>
            <Button
              asChild
              variant="outline"
              size="sm"
              className="hidden border-white/10 bg-white/[0.03] text-white hover:bg-white/[0.08] hover:text-white sm:inline-flex"
            >
              <Link href="/">
                <ShoppingBag className="size-3.5" />
                Lihat toko
              </Link>
            </Button>
            <form action={logoutPath} method="post">
              <Button
                type="submit"
                variant="outline"
                size="sm"
                className="border-red-300/15 bg-red-300/[0.04] text-red-100 hover:bg-red-300/[0.1] hover:text-white"
              >
                <LogOut className="size-3.5" />
                <span className="hidden sm:inline">Keluar</span>
              </Button>
            </form>
          </div>
        </div>
      </header>

      <main>
        <Tabs orientation="vertical" value={activeTab} onValueChange={selectTab} className="mx-auto max-w-[1440px] gap-4 px-3 py-4 sm:px-5 lg:grid lg:grid-cols-[238px_minmax(0,1fr)]">
        {menuOpen && (
          <>
            <button
              type="button"
              onClick={() => setMenuOpen(false)}
              className="fixed inset-0 z-40 bg-black/75 lg:hidden"
              aria-label="Tutup navigasi panel"
            />
            <aside id="admin-mobile-navigation" role="dialog" aria-modal="true" className="fixed inset-y-0 left-0 z-50 flex w-[86vw] max-w-[310px] flex-col border-r border-white/[0.1] bg-[#0b0f18] shadow-2xl lg:hidden">
              <div className="flex h-14 items-center justify-between border-b border-white/[0.08] px-3">
                <div className="flex items-center gap-2">
                  <span className="grid size-8 place-items-center rounded-md bg-[#b9ff35] text-[11px] font-black tracking-[-0.08em] text-[#091006]">
                    LF
                  </span>
                  <div>
                    <strong className="block text-[11px] tracking-[0.15em]">LFAMILIA</strong>
                    <span className="block text-[8px] text-white/35">ADMIN CONTROL CENTER</span>
                  </div>
                </div>
                <Button
                  type="button"
                  variant="ghost"
                  size="icon"
                  onClick={() => setMenuOpen(false)}
                  className="text-white/65 hover:bg-white/10 hover:text-white"
                  aria-label="Tutup navigasi panel"
                >
                  <X className="size-4" />
                </Button>
              </div>
              <div className="flex-1 overflow-y-auto px-2 py-3">
                <MobilePanelNavigation navGroups={navGroups} activeTab={activeTab} onSelect={selectTab} />
              </div>
              <div className="border-t border-white/[0.08] p-3">
                <p className="truncate text-xs font-bold">{initialSession.name}</p>
                <p className="mt-0.5 truncate text-[10px] text-white/35">{initialSession.email}</p>
              </div>
            </aside>
          </>
        )}

        <TabsList className="hidden w-full flex-col items-stretch overflow-y-auto rounded-lg border border-white/[0.08] bg-[#0d1019] p-2 lg:sticky lg:top-[4.5rem] lg:!flex lg:!h-[calc(100vh-5.5rem)] lg:self-start">
          <div className="mb-2 rounded-md border border-white/[0.08] bg-white/[0.025] px-3 py-2.5">
            <p className="text-[10px] font-black tracking-[0.14em] text-white">LFAMILIA STORE</p>
            <p className="mt-0.5 text-[9px] text-white/34">{isOwner ? "Pemilik · akses penuh" : "Staff · operasional toko"}</p>
          </div>
          <PanelNavigation navGroups={navGroups} onSelect={selectTab} />
          <div className="mt-auto border-t border-white/[0.08] px-2 pt-3">
            <p className="truncate text-[10px] font-bold text-white/70">{initialSession.name}</p>
            <p className="mt-0.5 truncate text-[9px] text-white/32">{initialSession.email}</p>
          </div>
        </TabsList>

        <div className="min-w-0">
          <TabsContent value="overview" className="mt-0">
            <AdminOverview />
          </TabsContent>

          <TabsContent value="orders" className="mt-0">
            <AdminSection title="Pesanan realtime" description="Pantau pembayaran, provider otomatis, antrean manual, dan riwayat proses setiap pesanan.">
              <AdminOrderManager />
            </AdminSection>
          </TabsContent>

          <TabsContent value="support" className="mt-0">
            <AdminSection title="Bantuan & refund" description="Tanggapi kendala pelanggan dan pantau seluruh pengajuan refund dalam satu tempat.">
              <AdminSupportManager />
            </AdminSection>
          </TabsContent>

          <TabsContent value="products" className="mt-0">
            <AdminSection
              title="Produk & nominal"
              description={
                isOwner
                  ? "Kelola katalog, kategori, data pelanggan, gambar, pop-up informasi, nominal, harga, tab nominal, dan provider."
                  : "Staff dapat memperbarui gambar, jam layanan, instruksi, dan pop-up. Harga serta provider hanya tersedia untuk Pemilik."
              }
            >
              <AdminProductManager />
            </AdminSection>
          </TabsContent>

          <TabsContent value="content" className="mt-0 space-y-4">
            <AdminSection title="Identitas & kontak" description="Kelola logo, informasi toko, Discord, kategori, dan FAQ.">
              <AdminStorefrontManager role={initialSession.role} />
            </AdminSection>
            <AdminSection title="Konten pengalaman pelanggan" description="Atur banner, pop-up, berita, dan ulasan tanpa perlu mengubah kode.">
              <AdminExperienceManager role={initialSession.role} />
            </AdminSection>
          </TabsContent>

          {isOwner && (
            <>
              <TabsContent value="promotions" className="mt-0">
                <AdminSection title="Promo & diskon" description="Atur kode diskon dan harga promo terjadwal per nominal. Stok kode digital tetap dikelola di menu terpisah.">
                  <AdminPromotionManager role="owner" />
                </AdminSection>
              </TabsContent>

              <TabsContent value="vouchers" className="mt-0">
                <AdminSection title="Stok kode digital" description="Kelola stok terenkripsi, pengiriman, dan percobaan ulang kode otomatis.">
                  <AdminVoucherManager />
                </AdminSection>
              </TabsContent>

              <TabsContent value="payments" className="mt-0 space-y-4">
                <AdminSection title="Payment gateway" description="Atur DOKU untuk checkout serta top up wallet.">
                  <AdminWalletManager view="checkout" />
                </AdminSection>
                <AdminSection title="Metode pembayaran" description="Kelompokkan metode checkout, urutan, logo, dan status aktifnya.">
                  <AdminPaymentMethodManager />
                </AdminSection>
                <AdminSection title="Halaman pembayaran" description="Edit branding, teks, tombol, bantuan, dan elemen yang tampil pada halaman pembayaran pelanggan.">
                  <AdminPaymentPageManager />
                </AdminSection>
              </TabsContent>

              <TabsContent value="wallet" className="mt-0">
                <AdminSection title="Wallet pelanggan" description="Pantau saldo, mutasi, dan top up pelanggan yang masuk secara manual maupun otomatis.">
                  <div className="space-y-4">
                    <AdminMemberManager view="customers" />
                    <AdminWalletManager view="topups" />
                  </div>
                </AdminSection>
              </TabsContent>

              <TabsContent value="members" className="mt-0">
                <AdminSection title="Member & privilege" description="Atur benefit untuk BASIC, GOLD, DIAMOND, dan PLATINUM.">
                  <AdminMemberManager view="privileges" />
                </AdminSection>
              </TabsContent>

              <TabsContent value="team" className="mt-0">
                <AdminSection title="Staff & akses" description="Buat ID login staff, atur peran, dan ganti password tanpa memperlihatkan password lama.">
                  <AdminTeamManager />
                </AdminSection>
              </TabsContent>

              <TabsContent value="settings" className="mt-0 space-y-4">
                <AdminSection title="Keamanan & integrasi" description="Sesi panel, database, gateway, dan provider tetap terpisah serta aman.">
                  <div className="grid gap-3 sm:grid-cols-2">
                    <SettingCard title="Login panel" status="Aktif" text="Pemilik dan Staff memakai sesi panel khusus yang terpisah dari akun pelanggan." />
                    <SettingCard title="Pemulihan Pemilik" status="Cloudflare Access" text="Halaman setup pemilik tetap dilindungi oleh Cloudflare Access." />
                    <SettingCard title="Cloudflare D1" status="Terhubung" text="Katalog, konten, akun, pesanan, wallet, dan stok tersimpan di database." />
                    <SettingCard title="Gateway pembayaran" status="Integration Manager" text="QRIS, e-wallet, virtual account, dan callback memakai konfigurasi aktif yang terenkripsi." />
                    <SettingCard title="DigiFlazz" status="Integration Manager" text="Credential provider dapat dikelola terenkripsi; SKU dan callback tetap tervalidasi." />
                    <SettingCard title="Pengiriman kode" status="Khusus Pemilik" text="Website dan email Resend mengirim voucher tanpa membuka stok ke Staff." />
                  </div>
                </AdminSection>
                <AdminSection title="VPS Relay" description="Hubungkan Worker LFAMILIA ke relay DigiFlazz ber-IP statis.">
                  <AdminIntegrationManager view="relay" />
                </AdminSection>
                <AdminSection title="Kredensial API & callback" description="Simpan credential DOKU dan DigiFlazz terenkripsi, pilih environment aktif, dan salin URL callback yang diperlukan.">
                  <AdminIntegrationManager view="providers" />
                </AdminSection>
                <AdminSection title="Harga otomatis DigiFlazz" description="Sinkronkan daftar harga DigiFlazz dan atur margin penjualan per nominal.">
                  <AdminDigiflazzPricing />
                </AdminSection>
              </TabsContent>
            </>
          )}
        </div>
        </Tabs>
      </main>
    </div>
  );
}

function MobilePanelNavigation({
  navGroups,
  activeTab,
  onSelect,
}: {
  navGroups: NavigationGroup[];
  activeTab: string;
  onSelect: (value: string) => void;
}) {
  return (
    <nav className="space-y-3" aria-label="Navigasi panel mobile">
      {navGroups.map((group) => (
        <div key={group.label}>
          <p className="px-2 pb-1 text-[8px] font-black uppercase tracking-[0.16em] text-white/28">{group.label}</p>
          <div className="space-y-0.5">
            {group.items.map(({ value, label, Icon }) => {
              const active = value === activeTab;
              return (
                <button
                  key={value}
                  type="button"
                  onClick={() => onSelect(value)}
                  className={
                    "flex h-10 w-full items-center rounded-md px-2.5 text-left text-[11px] font-medium transition " +
                    (active
                      ? "bg-[#b9ff35] font-bold text-[#091006]"
                      : "text-white/58 hover:bg-white/[0.06] hover:text-white")
                  }
                >
                  <Icon className="mr-2.5 size-3.5 shrink-0" />
                  <span className="min-w-0 flex-1 truncate">{label}</span>
                </button>
              );
            })}
          </div>
        </div>
      ))}
    </nav>
  );
}

function PanelNavigation({
  navGroups,
  onSelect,
}: {
  navGroups: NavigationGroup[];
  onSelect: (value: string) => void;
}) {
  return (
    <nav className="space-y-3" aria-label="Navigasi panel">
      {navGroups.map((group) => (
        <div key={group.label}>
          <p className="px-2 pb-1 text-[8px] font-black uppercase tracking-[0.16em] text-white/28">{group.label}</p>
          <div className="space-y-0.5">
            {group.items.map(({ value, label, Icon }) => (
              <TabsTrigger
                key={value}
                value={value}
                onClick={() => onSelect(value)}
                className={
                  "flex h-9 w-full justify-start rounded-md px-2.5 text-left text-[11px] font-medium text-white/52 transition hover:bg-white/[0.06] hover:text-white data-[state=active]:bg-[#b9ff35] data-[state=active]:font-bold data-[state=active]:text-[#091006]"
                }
              >
                <Icon className="mr-2.5 size-3.5 shrink-0" />
                <span className="min-w-0 flex-1 truncate">{label}</span>
              </TabsTrigger>
            ))}
          </div>
        </div>
      ))}
    </nav>
  );
}

function AdminSection({
  title,
  description,
  children,
}: {
  title: string;
  description: string;
  children: ReactNode;
}) {
  return (
    <section className="overflow-hidden rounded-lg border border-white/[0.08] bg-[#0d1019]">
      <div className="flex flex-col gap-1 border-b border-white/[0.08] bg-white/[0.015] px-4 py-3">
        <h2 className="text-sm font-bold tracking-[-0.01em]">{title}</h2>
        <p className="text-[10px] leading-4 text-white/38">{description}</p>
      </div>
      <div className="p-3 sm:p-4">{children}</div>
    </section>
  );
}

function SettingCard({
  title,
  status,
  text,
}: {
  title: string;
  status: string;
  text: string;
}) {
  return (
    <div className="rounded-md border border-white/[0.08] bg-white/[0.02] p-3">
      <div className="flex items-start justify-between gap-3">
        <strong className="text-xs">{title}</strong>
        <span className="rounded-md bg-white/[0.06] px-1.5 py-0.5 text-[8px] font-bold text-white/50">{status}</span>
      </div>
      <p className="mt-2 text-[10px] leading-4 text-white/36">{text}</p>
    </div>
  );
}
