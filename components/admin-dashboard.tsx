"use client";

import { useState } from "react";
import Link from "next/link";
import {
  Box,
  FileQuestion,
  LayoutDashboard,
  LoaderCircle,
  LogOut,
  Menu,
  ReceiptText,
  Settings,
  ShieldCheck,
  ShoppingBag,
  TicketPercent,
  Users,
  WalletCards,
  CreditCard,
  MessageCircleMore,
  X,
} from "lucide-react";
import { Button } from "@/components/ui/button";
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs";
import { AdminOrderManager } from "@/components/admin-order-manager";
import { AdminOverview } from "@/components/admin-overview";
import { AdminExperienceManager } from "@/components/admin-experience-manager";
import { AdminMemberManager } from "@/components/admin-member-manager";
import { AdminProductManager } from "@/components/admin-product-manager";
import { AdminPromotionManager } from "@/components/admin-promotion-manager";
import { AdminStorefrontManager } from "@/components/admin-storefront-manager";
import { AdminTeamManager } from "@/components/admin-team-manager";
import { AdminVoucherManager } from "@/components/admin-voucher-manager";
import { AdminWalletManager } from "@/components/admin-wallet-manager";
import { AdminPaymentMethodManager } from "@/components/admin-payment-method-manager";
import { AdminSupportManager } from "@/components/admin-support-manager";
import { AdminDigiflazzPricing } from "@/components/admin-digiflazz-pricing";
import { StoreLayout } from "@/components/store-layout";

type Session = {
  id: number;
  email: string;
  name: string;
  role: "owner" | "staff";
};

const baseNav = [
  ["overview", "Ringkasan", LayoutDashboard],
  ["orders", "Pesanan", ReceiptText],
  ["support", "Bantuan", MessageCircleMore],
  ["products", "Produk", Box],
  ["content", "Konten", FileQuestion],
] as const;

const ownerNav = [
  ["promotions", "Voucher diskon", TicketPercent],
  ["members", "Member & Privilege", ShieldCheck],
  ["wallet", "Saldo pelanggan", WalletCards],
  ["payments", "Pembayaran", CreditCard],
  ["vouchers", "Stok kode", TicketPercent],
  ["team", "Tim admin", Users],
  ["settings", "Integrasi", Settings],
] as const;

export function AdminDashboard({
  expectedRole,
  initialSession,
}: {
  expectedRole: "owner" | "staff";
  initialSession: Session;
}) {
  const session = initialSession;
  const [loggingOut, setLoggingOut] = useState(false);
  const [menuOpen, setMenuOpen] = useState(false);
  const [activeTab, setActiveTab] = useState("overview");

  async function logout() {
    setLoggingOut(true);
    try {
      const apiBase = expectedRole === "owner" ? "/api/admin/panel" : "/api/staff";
      await fetch(`${apiBase}/auth/logout`, { method: "POST", credentials: "same-origin", cache: "no-store" });
    } finally {
      window.location.replace(expectedRole === "owner" ? "/admin/panel/login" : "/staff/panel/login");
    }
  }

  const isOwner = session.role === "owner";
  const nav = isOwner ? [...baseNav, ...ownerNav] : baseNav;

  return (
    <StoreLayout>
      <main className="mx-auto min-h-[75vh] max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
        <div className="mb-7 flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
          <div>
            <div className="flex flex-wrap items-center gap-2">
              <p className="eyebrow !mb-0">{expectedRole === "owner" ? "Panel Admin" : "Panel Staff"}</p>
              <span
                className={`rounded-full px-2 py-0.5 text-[8px] font-black uppercase ${isOwner ? "bg-[#b9ff35]/10 text-[#d8ff8d]" : "bg-blue-400/10 text-blue-300"}`}
              >
                {isOwner ? "Pemilik" : "Staff"}
              </span>
            </div>
            <h1 className="mt-2 text-3xl font-black tracking-[-0.04em] sm:text-4xl">
              {isOwner ? "Dashboard Pemilik" : "Dashboard Staff"}
            </h1>
            <p className="mt-2 text-xs text-white/35">
              {isOwner
                ? "Kontrol toko, katalog, keuangan, pembayaran, dan tim."
                : "Pantau operasional pesanan dan informasi katalog."}{" "}
              • {session.name}
            </p>
          </div>
          <div className="flex flex-wrap gap-2">
            <Button
              type="button"
              onClick={() => setMenuOpen(true)}
              variant="outline"
              className="rounded-xl border-white/10 bg-white/[0.035] text-white lg:hidden"
            >
              <Menu className="size-4" />
            </Button>
            <Button
              asChild
              variant="outline"
              className="rounded-xl border-white/10 bg-white/[0.035] text-white hover:bg-white/[0.08] hover:text-white"
            >
              <Link href="/">
                <ShoppingBag className="mr-2 size-4" />
                Lihat toko
              </Link>
            </Button>
            <Button
              type="button"
              variant="outline"
              disabled={loggingOut}
              onClick={() => void logout()}
              className="rounded-xl border-red-300/15 bg-red-300/[0.04] text-red-100 hover:bg-red-300/[0.1] hover:text-white"
            >
              {loggingOut ? (
                <LoaderCircle className="mr-2 size-4 animate-spin" />
              ) : (
                <LogOut className="mr-2 size-4" />
              )}
              Keluar
            </Button>
          </div>
        </div>

        <Tabs
          value={activeTab}
          onValueChange={setActiveTab}
          className="grid items-start gap-5 lg:grid-cols-[220px_1fr]"
        >
          {menuOpen && (
            <>
              <button
                type="button"
                onClick={() => setMenuOpen(false)}
                className="fixed inset-0 z-40 bg-black/70 lg:hidden"
                aria-label="Tutup menu"
              />
              <aside className="fixed inset-y-0 left-0 z-50 w-[82vw] max-w-72 overflow-y-auto border-r border-white/[0.1] bg-[#0d1019] p-3 shadow-2xl lg:hidden">
                <div className="mb-4 flex items-center justify-between px-2 pt-1">
                  <p className="text-xs font-black uppercase tracking-[0.18em] text-[#c9ff70]">
                    Menu panel
                  </p>
                  <Button
                    type="button"
                    variant="ghost"
                    size="icon"
                    onClick={() => setMenuOpen(false)}
                    className="size-9 text-white/70 hover:bg-white/10 hover:text-white"
                  >
                    <X className="size-4" />
                  </Button>
                </div>
                <div className="space-y-1">
                  {nav.map(([value, label, Icon]) => (
                    <button
                      key={value}
                      type="button"
                      onClick={() => {
                        setActiveTab(value);
                        setMenuOpen(false);
                      }}
                      className={`flex h-11 w-full items-center rounded-xl px-3 text-left text-sm font-medium transition ${activeTab === value ? "bg-[#b9ff35] text-[#091006]" : "text-white/65 hover:bg-white/[0.08] hover:text-white"}`}
                    >
                      <Icon className="mr-3 size-4" />
                      {label}
                    </button>
                  ))}
                </div>
              </aside>
            </>
          )}
          <TabsList className="hidden h-auto gap-2 rounded-2xl border border-white/[0.08] bg-[#0d1019] p-2 lg:sticky lg:top-28 lg:!flex lg:!w-full lg:!flex-col lg:!items-stretch">
            {nav.map(([value, label, Icon]) => (
              <TabsTrigger
                key={value}
                value={value}
                onClick={() => setMenuOpen(false)}
                className="h-10 w-full shrink-0 justify-start rounded-xl px-3 text-xs text-white/42 data-[state=active]:bg-[#b9ff35] data-[state=active]:text-[#091006]"
              >
                <Icon className="mr-2 size-4" />
                {label}
              </TabsTrigger>
            ))}
          </TabsList>

          <div className="min-w-0">
            <TabsContent value="overview" className="mt-0">
              <AdminOverview />
            </TabsContent>
            <TabsContent value="orders" className="mt-0">
              <AdminSection
                title="Daftar pesanan"
                description="Pantau pembayaran, provider otomatis, dan antrean manual."
              >
                <AdminOrderManager />
              </AdminSection>
            </TabsContent>
            <TabsContent value="support" className="mt-0">
              <AdminSection
                title="Bantuan & refund"
                description="Tanggapi kendala pelanggan dan pantau status pengajuan refund."
              >
                <AdminSupportManager />
              </AdminSection>
            </TabsContent>
            <TabsContent value="products" className="mt-0">
              <AdminSection
                title="Katalog produk"
                description={
                  isOwner
                    ? "Kelola produk, data yang diisi pelanggan, gambar, pop-up informasi, nominal, harga, dan provider."
                    : "Staff dapat memperbarui gambar, jam layanan, instruksi, dan pop-up. Harga serta provider hanya tersedia untuk Pemilik."
                }
              >
                <AdminProductManager />
              </AdminSection>
            </TabsContent>
            <TabsContent value="content" className="mt-0 space-y-5">
              <AdminSection
                title="Identitas & kontak"
                description="Kelola logo, kontak, Discord, kategori, dan FAQ."
              >
                <AdminStorefrontManager role={session.role} />
              </AdminSection>
              <AdminSection
                title="Banner, pop-up, berita & ulasan"
                description="Semua konten pengalaman pelanggan dapat diedit dari sini tanpa mengubah kode."
              >
                <AdminExperienceManager role={session.role} />
              </AdminSection>
            </TabsContent>
            {isOwner && (
              <>
                <TabsContent value="promotions" className="mt-0">
                  <AdminSection
                    title="Voucher diskon"
                    description="Atur kode, periode, minimum pembelian, kuota, dan batas diskon."
                  >
                    <AdminPromotionManager role="owner" />
                  </AdminSection>
                </TabsContent>
                <TabsContent value="members" className="mt-0">
                  <AdminSection
                    title="Member & privilege"
                    description="Atur diskon dan benefit untuk BASIC, GOLD, DIAMOND, dan PLATINUM."
                  >
                    <AdminMemberManager view="privileges" />
                  </AdminSection>
                </TabsContent>
                <TabsContent value="wallet" className="mt-0">
                  <AdminSection
                    title="Saldo pelanggan"
                    description="Kelola saldo dan role customer. Top up pelanggan diproses otomatis oleh payment gateway."
                  >
                    <div className="space-y-5">
                      <AdminMemberManager view="customers" />
                      <AdminWalletManager view="topups" />
                    </div>
                  </AdminSection>
                </TabsContent>
                <TabsContent value="payments" className="mt-0 space-y-5">
                  <AdminSection
                    title="Payment gateway"
                    description="Midtrans dan iPaymu diletakkan paling atas. Klik ikon pensil untuk mengubah pengaturan."
                  >
                    <AdminWalletManager view="checkout" />
                  </AdminSection>
                  <AdminSection
                    title="Metode pembayaran"
                    description="Daftar metode dibuat ringkas. Klik ikon pensil untuk mengubah logo dan detail."
                  >
                    <AdminPaymentMethodManager />
                  </AdminSection>
                </TabsContent>
                <TabsContent value="vouchers" className="mt-0">
                  <AdminSection
                    title="Stok kode otomatis"
                    description="Kode digital terenkripsi, pengiriman, dan percobaan ulang hanya dapat diakses Pemilik."
                  >
                    <AdminVoucherManager />
                  </AdminSection>
                </TabsContent>
                <TabsContent value="team" className="mt-0">
                  <AdminSection
                    title="Pemilik & Staff"
                    description="Buat ID login Staff, atur peran, dan ganti password tanpa memperlihatkan password lama."
                  >
                    <AdminTeamManager />
                  </AdminSection>
                </TabsContent>
                <TabsContent value="settings" className="mt-0 space-y-5">
                  <AdminSection
                    title="Keamanan & integrasi"
                    description="Login panel terpisah dari pelanggan; secret provider tetap dikelola aman melalui Cloudflare."
                  >
                    <div className="grid gap-4 sm:grid-cols-2">
                      <SettingCard
                        title="Login ID & password"
                        status="Aktif"
                        text="Pemilik dan Staff memakai sesi panel khusus yang terpisah dari akun pelanggan."
                      />
                      <SettingCard
                        title="Pemulihan Pemilik"
                        status="Cloudflare Access"
                        text="Halaman /admin/setup hanya dapat dibuka melalui email Pemilik yang dilindungi Cloudflare."
                      />
                      <SettingCard
                        title="Cloudflare D1"
                        status="Terhubung"
                        text="Katalog, konten, akun, tim, pesanan, saldo, dan stok kode tersimpan di database."
                      />
                      <SettingCard
                        title="Gateway pembayaran"
                        status="Secret Cloudflare"
                        text="Virtual Account, dompet digital, QRIS, dan callback pembayaran."
                      />
                      <SettingCard
                        title="DigiFlazz"
                        status="Secret Cloudflare"
                        text="Produk otomatis menggunakan SKU per nominal dan callback tervalidasi."
                      />
                      <SettingCard
                        title="VIPayment"
                        status="Secret Cloudflare"
                        text="Provider alternatif resmi untuk produk otomatis."
                      />
                      <SettingCard
                        title="Pengiriman kode"
                        status="Khusus Pemilik"
                        text="Email dan WhatsApp mengirim voucher tanpa memperlihatkan stok ke Staff."
                      />
                    </div>
                    <div className="mt-4 rounded-xl border border-amber-300/20 bg-amber-300/[0.06] p-4 text-[10px] leading-5 text-amber-100/60">
                      <ShieldCheck className="mb-2 size-4 text-amber-300" />
                      Password disimpan sebagai hash dan tidak dapat dibaca
                      kembali. Secret pembayaran maupun provider juga tidak
                      pernah ditampilkan di browser.
                    </div>
                  </AdminSection>
                  <AdminSection
                    title="Harga otomatis DigiFlazz"
                    description="Sinkronkan daftar harga DigiFlazz dan atur margin penjualan."
                  >
                    <AdminDigiflazzPricing />
                  </AdminSection>
                </TabsContent>
              </>
            )}
          </div>
        </Tabs>
      </main>
    </StoreLayout>
  );
}

function AdminSection({
  title,
  description,
  children,
}: {
  title: string;
  description: string;
  children: React.ReactNode;
}) {
  return (
    <section className="panel overflow-hidden">
      <div className="border-b border-white/[0.08] p-5">
        <h2 className="font-bold">{title}</h2>
        <p className="mt-1 text-[10px] text-white/30">{description}</p>
      </div>
      <div className="p-4 sm:p-5">{children}</div>
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
    <div className="rounded-xl border border-white/[0.08] bg-white/[0.02] p-4">
      <div className="flex items-start justify-between gap-3">
        <strong className="text-sm">{title}</strong>
        <span className="rounded-full bg-white/[0.06] px-2 py-1 text-[8px] font-bold text-white/45">
          {status}
        </span>
      </div>
      <p className="mt-3 text-[10px] leading-5 text-white/30">{text}</p>
    </div>
  );
}