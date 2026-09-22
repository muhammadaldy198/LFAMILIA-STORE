"use client";

import { useEffect, useRef, useState, type FormEvent } from "react";
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
  Layers3,
  LifeBuoy,
  Menu,
  PackageSearch,
  Search,
  Settings,
  Sparkles,
  UserCog,
  Users,
  X,
} from "lucide-react";
import { Input } from "@/components/ui/input";
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs";
import { AdminAccountMenu } from "@/components/admin-account-menu";
import { AdminNotifications } from "@/components/admin-notifications";
import { AdminDigiflazzWorkspace } from "@/components/admin-digiflazz-workspace";
import { AdminExperienceManager } from "@/components/admin-experience-manager";
import { AdminIntegrationWorkspace } from "@/components/admin-integration-workspace";
import { AdminKokinpayWorkspace } from "@/components/admin-kokinpay-workspace";
import { AdminOrderManager } from "@/components/admin-order-manager";
import { AdminOverview } from "@/components/admin-overview";
import { AdminPaymentWorkspace } from "@/components/admin-payment-workspace";
import { AdminProductManager } from "@/components/admin-product-manager";
import { StaffProductContentWorkspace } from "@/components/staff-product-content-workspace";
import { AdminCustomerDirectory } from "@/components/admin-customer-directory";
import { AdminCustomerWorkspace } from "@/components/admin-customer-workspace";
import { AdminPromoWorkspace, AdminReportsWorkspace, AdminSettingsWorkspace, AdminSupportWorkspace, AdminTeamWorkspace } from "@/components/admin-operations-workspaces";

type Session = {
  id: number;
  email: string;
  name: string;
  role: "super_admin" | "admin" | "staff";
};

type GlobalSearchResult = {
  id: string;
  tab: string;
  title: string;
  detail: string;
};

type NavigationItem = {
  value: string;
  label: string;
  Icon: LucideIcon;
  minimumRole: "staff" | "admin" | "super_admin";
};

const navigation: NavigationItem[] = [
  { value: "overview", label: "Dashboard", Icon: LayoutDashboard, minimumRole: "staff" },
  { value: "orders", label: "Pesanan", Icon: FileText, minimumRole: "staff" },
  { value: "products", label: "Produk", Icon: Boxes, minimumRole: "admin" },
  { value: "content", label: "Banner & Konten", Icon: ImageIcon, minimumRole: "staff" },
  { value: "digiflazz", label: "Digiflazz", Icon: PackageSearch, minimumRole: "admin" },
  { value: "account-validation", label: "Validasi Akun", Icon: Gamepad2, minimumRole: "admin" },
  { value: "payments", label: "Pembayaran", Icon: CreditCard, minimumRole: "admin" },
  { value: "customers", label: "Pelanggan", Icon: Users, minimumRole: "admin" },
  { value: "promotions", label: "Promo", Icon: Sparkles, minimumRole: "admin" },
  { value: "support", label: "Layanan Pelanggan", Icon: Headphones, minimumRole: "staff" },
  { value: "reports", label: "Laporan", Icon: BarChart3, minimumRole: "admin" },
  { value: "team", label: "Staff & Admin Akses", Icon: UserCog, minimumRole: "super_admin" },
  { value: "integrations", label: "Integrasi", Icon: Layers3, minimumRole: "super_admin" },
  { value: "settings", label: "Pengaturan", Icon: Settings, minimumRole: "super_admin" },
];

const roleRank = { staff: 0, admin: 1, super_admin: 2 } as const;

export function AdminDashboard({
  expectedRole,
  initialSession,
}: {
  expectedRole: "backoffice" | "staff";
  initialSession: Session;
}) {
  const [activeTab, setActiveTab] = useState("overview");
  const [globalSearch, setGlobalSearch] = useState("");
  const [globalResults, setGlobalResults] = useState<GlobalSearchResult[]>([]);
  const [globalSearchBusy, setGlobalSearchBusy] = useState(false);
  const [globalSearchError, setGlobalSearchError] = useState("");
  const [mobileNavigationOpen, setMobileNavigationOpen] = useState(false);
  const searchRef = useRef<HTMLInputElement>(null);
  const globalSearchRequestRef = useRef(0);
  const isOwner = initialSession.role === "super_admin";
  const isAdmin = initialSession.role === "admin";
  const visibleNavigation = navigation.filter((item) => roleRank[initialSession.role] >= roleRank[item.minimumRole]);
  const logoutPath = expectedRole === "backoffice" ? "/admin/panel/auth/logout" : "/staff/panel/auth/logout";

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

  async function submitGlobalSearch(event: FormEvent) {
    event.preventDefault();
    const rawQuery = globalSearch.trim();
    const query = rawQuery.toLowerCase();
    if (!query) return;
    const requestId = ++globalSearchRequestRef.current;

    const target = visibleNavigation.find((item) => item.label.toLowerCase().includes(query));
    if (target) {
      setActiveTab(target.value);
      setGlobalResults([]);
      setGlobalSearchError("");
      setGlobalSearchBusy(false);
      setMobileNavigationOpen(false);
      return;
    }

    setGlobalSearchBusy(true);
    setGlobalSearchError("");
    try {
      const requests: Array<Promise<Response>> = [
        fetch("/api/panel/orders", { cache: "no-store" }),
      ];
      const canSearchBackoffice = isOwner || isAdmin;
      if (canSearchBackoffice) {
        requests.push(fetch("/api/panel/products", { cache: "no-store" }));
        requests.push(fetch("/api/panel/members", { cache: "no-store" }));
      }
      const responses = await Promise.all(requests);
      const payloads = await Promise.all(responses.map((response) => response.json().catch(() => ({}))));
      const sources = canSearchBackoffice ? ["pesanan", "produk", "pelanggan"] : ["pesanan"];
      for (let index = 0; index < responses.length; index += 1) {
        if (!responses[index].ok) {
          const payload = payloads[index] as { error?: string };
          throw new Error(payload.error || `Pencarian ${sources[index]} gagal dimuat.`);
        }
      }
      if (globalSearchRequestRef.current !== requestId) return;
      const results: GlobalSearchResult[] = [];

      const orders = (payloads[0] as { orders?: Array<{ id?: string | number; reference_id?: string; buyer_name?: string; buyer_email?: string; product_name?: string; package_label?: string; destination?: string }> }).orders ?? [];
      for (const order of orders) {
        const haystack = [order.reference_id, order.buyer_name, order.buyer_email, order.product_name, order.package_label, order.destination].filter(Boolean).join(" ").toLowerCase();
        if (haystack.includes(query)) {
          results.push({
            id: `order:${order.id ?? order.reference_id}`,
            tab: "orders",
            title: order.reference_id || "Pesanan",
            detail: [order.buyer_name, order.product_name, order.package_label].filter(Boolean).join(" · "),
          });
        }
      }

      if (canSearchBackoffice) {
        const products = (payloads[1] as { products?: Array<{ dbId?: number | null; slug?: string; name?: string; category?: string; packages?: Array<{ id?: string; label?: string; providerSku?: string }> }> }).products ?? [];
        for (const product of products) {
          const packageText = (product.packages ?? []).flatMap((item) => [item.id, item.label, item.providerSku]).filter(Boolean).join(" ");
          const haystack = [product.name, product.slug, product.category, packageText].filter(Boolean).join(" ").toLowerCase();
          if (haystack.includes(query)) {
            results.push({
              id: `product:${product.dbId ?? product.slug}`,
              tab: "products",
              title: product.name || product.slug || "Produk",
              detail: [product.category, product.slug].filter(Boolean).join(" · "),
            });
          }
        }

        const members = (payloads[2] as { members?: Array<{ id?: string; name?: string; email?: string; phone?: string }> }).members ?? [];
        for (const member of members) {
          const haystack = [member.name, member.email, member.phone].filter(Boolean).join(" ").toLowerCase();
          if (haystack.includes(query)) {
            results.push({
              id: `customer:${member.id ?? member.email}`,
              tab: "customers",
              title: member.name || member.email || "Pelanggan",
              detail: [member.email, member.phone].filter(Boolean).join(" · "),
            });
          }
        }
      }

      if (globalSearchRequestRef.current !== requestId) return;
      setGlobalResults(results.slice(0, 12));
      if (!results.length) setGlobalSearchError(`Tidak ada hasil untuk “${rawQuery}”.`);
    } catch (reason) {
      if (globalSearchRequestRef.current !== requestId) return;
      setGlobalResults([]);
      setGlobalSearchError(reason instanceof Error ? reason.message : "Pencarian global gagal.");
    } finally {
      if (globalSearchRequestRef.current === requestId) setGlobalSearchBusy(false);
    }
  }

  return (
    <Tabs
      value={activeTab}
      onValueChange={setActiveTab}
      className="admin-reference min-h-screen bg-[#f4f7fb] text-[#0f1f3d] lg:grid lg:grid-cols-[230px_minmax(0,1fr)]"
    >
      {mobileNavigationOpen && <button type="button" aria-label="Tutup menu" onClick={() => setMobileNavigationOpen(false)} className="fixed inset-0 z-40 bg-[#071426]/55 lg:hidden" />}
      <aside className={`fixed inset-y-0 left-0 z-50 admin-mobile-drawer flex h-dvh w-[280px] flex-col overflow-hidden bg-[#112842] text-white shadow-[6px_0_24px_rgba(15,37,64,0.22)] transition-transform duration-200 lg:sticky lg:top-0 lg:z-auto lg:w-auto lg:translate-x-0 lg:shadow-[6px_0_24px_rgba(15,37,64,0.12)] ${mobileNavigationOpen ? "translate-x-0" : "-translate-x-full"}`}>
        <Brand onClick={() => { setActiveTab("overview"); setMobileNavigationOpen(false); }} onClose={() => setMobileNavigationOpen(false)} />

        <div className="min-h-0 flex-1 overflow-y-auto px-2.5 py-3 scrollbar-none">
          <TabsList className="h-auto w-full flex-col items-stretch gap-1 bg-transparent p-0">
            {visibleNavigation.map(({ value, label, Icon }) => (
              <TabsTrigger
                key={value}
                value={value}
                onClick={() => setMobileNavigationOpen(false)}
                className="flex h-11 w-full justify-start rounded-md px-3 text-left text-[13px] font-medium text-slate-200/90 transition hover:bg-white/[0.08] hover:text-white data-[state=active]:bg-[#1769e8] data-[state=active]:text-white data-[state=active]:shadow-[0_5px_16px_rgba(23,105,232,0.28)] lg:h-10 lg:text-[12px]"
              >
                <Icon className="mr-3 size-[17px] shrink-0" strokeWidth={1.9} />
                <span className="truncate">{label}</span>
              </TabsTrigger>
            ))}
          </TabsList>
        </div>

        <SidebarHelp onClick={() => setActiveTab("support")} />
        <div className="px-5 pb-5 pt-2 text-[9px] leading-4 text-slate-400">
          <p>© {new Date().getFullYear()} LFAMILIA</p>
          <p>Top Up Game Solution</p>
          <p>v1.0.0</p>
        </div>
      </aside>

      <div className="min-w-0 lg:col-start-2">
        <header className="sticky top-0 z-30 flex h-[56px] items-center gap-2 border-b border-[#e5eaf1] bg-white px-3 shadow-[0_1px_2px_rgba(15,23,42,0.02)] lg:h-[58px] lg:px-5">
          <button type="button" onClick={() => setMobileNavigationOpen(true)} aria-label="Buka menu admin" className="grid size-10 shrink-0 place-items-center rounded-md border border-[#dfe5ed] bg-[#f8fafc] text-[#183451] lg:hidden"><Menu className="size-5" /></button>
          <form onSubmit={submitGlobalSearch} className="relative min-w-0 flex-1 max-w-[550px]">
            <Search className="absolute left-3 top-1/2 size-4 -translate-y-1/2 text-[#7d8ba3]" />
            <Input
              ref={searchRef}
              value={globalSearch}
              onChange={(event) => { globalSearchRequestRef.current += 1; setGlobalSearchBusy(false); setGlobalSearch(event.target.value); setGlobalResults([]); setGlobalSearchError(""); }}
              placeholder="Cari menu, produk, pesanan, atau pelanggan..."
              className="h-9 rounded-md border-[#dfe5ed] bg-[#f8fafc] pl-9 pr-3 sm:pr-20 text-[11px] text-[#26364f] shadow-none placeholder:text-[#98a5b8] focus-visible:ring-[#1769e8]/30"
            />
            <kbd className="pointer-events-none absolute right-2.5 top-1/2 hidden -translate-y-1/2 rounded border border-[#dfe5ed] bg-white px-1.5 py-0.5 text-[9px] font-semibold text-[#8b98aa] sm:block">
              {globalSearchBusy ? "..." : "Ctrl K"}
            </kbd>
            {(globalResults.length > 0 || globalSearchError) && (
              <div className="absolute left-0 right-0 top-[42px] z-50 overflow-hidden rounded-md border border-[#dfe5ed] bg-white shadow-xl">
                {globalSearchError && <p className="px-3 py-3 text-[10px] text-[#8a3b3b]">{globalSearchError}</p>}
                {globalResults.map((result) => (
                  <button
                    key={result.id}
                    type="button"
                    onClick={() => {
                      setActiveTab(result.tab);
                      setGlobalResults([]);
                      setMobileNavigationOpen(false);
                    }}
                    className="block w-full border-t border-[#eef1f5] px-3 py-2.5 text-left first:border-0 hover:bg-[#f7f9fc]"
                  >
                    <strong className="block text-[10px] text-[#20324c]">{result.title}</strong>
                    <span className="mt-0.5 block truncate text-[9px] text-[#7c8a9d]">{result.detail}</span>
                  </button>
                ))}
              </div>
            )}
          </form>

          <div className="ml-auto flex shrink-0 items-center gap-2 lg:gap-3">
            <AdminNotifications sessionId={initialSession.id} isOwner={isOwner} onNavigate={setActiveTab} />
            <span className="hidden h-7 w-px bg-[#e7ebf1] sm:block" />
            <AdminAccountMenu session={initialSession} logoutPath={logoutPath} onNavigate={setActiveTab} />
          </div>
        </header>

        <main className="admin-v3-content min-w-0 overflow-x-hidden p-3 sm:p-4 lg:p-5">
          <div className="mx-auto min-w-0 max-w-[1540px]">
            <TabsContent value="overview" className="mt-0"><AdminOverview role={initialSession.role} onNavigate={setActiveTab} /></TabsContent>
            <TabsContent value="orders" className="mt-0"><AdminOrderManager /></TabsContent>
            {(isOwner || isAdmin) && <TabsContent value="products" className="mt-0"><AdminProductManager /></TabsContent>}
            <TabsContent value="content" className="mt-0"><AdminExperienceManager role={initialSession.role} />{initialSession.role === "staff" && <StaffProductContentWorkspace />}</TabsContent>

            {(isOwner || isAdmin) && <TabsContent value="digiflazz" className="mt-0"><AdminDigiflazzWorkspace /></TabsContent>}
            {(isOwner || isAdmin) && <TabsContent value="account-validation" className="mt-0"><AdminKokinpayWorkspace /></TabsContent>}
            {(isOwner || isAdmin) && <TabsContent value="payments" className="mt-0"><AdminPaymentWorkspace /></TabsContent>}
            {(isOwner || isAdmin) && <TabsContent value="customers" className="mt-0">{isOwner ? <AdminCustomerWorkspace /> : <AdminCustomerDirectory />}</TabsContent>}
            {(isOwner || isAdmin) && <TabsContent value="promotions" className="mt-0"><AdminPromoWorkspace /></TabsContent>}
            <TabsContent value="support" className="mt-0"><AdminSupportWorkspace /></TabsContent>
            {(isOwner || isAdmin) && <TabsContent value="reports" className="mt-0"><AdminReportsWorkspace /></TabsContent>}
            {isOwner && <TabsContent value="team" className="mt-0"><AdminTeamWorkspace /></TabsContent>}
            {isOwner && <TabsContent value="integrations" className="mt-0"><AdminIntegrationWorkspace /></TabsContent>}
            {isOwner && <TabsContent value="settings" className="mt-0"><AdminSettingsWorkspace /></TabsContent>}
          </div>
        </main>
      </div>
      <style>{`@media (max-width: 1023px) {
        .admin-v3-content .grid { grid-template-columns: minmax(0, 1fr) !important; }
        .admin-v3-content .col-span-2 { grid-column: span 1 / span 1 !important; }
        .admin-v3-content .grid > .col-span-2 { grid-column: span 1 / span 1 !important; }
        .admin-v3-content .flex.items-start.justify-between,
        .admin-v3-content .flex.items-center.justify-between { flex-wrap: wrap; }
        .admin-v3-content .overflow-x-auto { -webkit-overflow-scrolling: touch; }
        .admin-v3-content [role="dialog"] { padding: 12px !important; }
        .admin-v3-content .text-\\[6px\\], .admin-v3-content .text-\\[6\.5px\\] { font-size: 10px !important; }
        .admin-v3-content .text-\\[7px\\], .admin-v3-content .text-\\[7\.5px\\], .admin-v3-content .text-\\[8px\\] { font-size: 11px !important; }
        .admin-v3-content .text-\\[8\.5px\\], .admin-v3-content .text-\\[9px\\] { font-size: 12px !important; }
        .admin-v3-content .text-\\[10px\\] { font-size: 13px !important; }
      }`}</style>
    </Tabs>
  );
}

function Brand({ onClick, onClose }: { onClick(): void; onClose(): void }) {
  return (
    <div className="flex h-[70px] items-center gap-2 border-b border-white/[0.07] px-4">
      <button type="button" onClick={onClick} className="flex min-w-0 flex-1 items-center gap-3 text-left">
        <span className="grid size-[42px] place-items-center rounded-full bg-gradient-to-br from-[#2483ff] to-[#0b56c8] shadow-[0_8px_22px_rgba(17,99,224,0.35)]"><Gamepad2 className="size-6 text-white" strokeWidth={2.2} /></span>
        <span className="min-w-0"><strong className="block truncate text-[14px] font-extrabold tracking-[-0.02em] text-white">LFAMILIA ADMIN</strong><span className="mt-0.5 block truncate text-[8px] font-medium text-slate-400">Top Up Game Solution</span></span>
      </button>
      <button type="button" onClick={onClose} aria-label="Tutup menu" className="grid size-9 place-items-center rounded-md text-slate-300 hover:bg-white/[0.08] lg:hidden"><X className="size-5" /></button>
    </div>
  );
}

function SidebarHelp({ onClick }: { onClick(): void }) {
  return (
    <div className="px-4 pb-3">
      <div className="rounded-lg bg-white/[0.055] p-3">
        <div className="flex items-start gap-2.5">
          <span className="grid size-8 shrink-0 place-items-center rounded-full border border-white/20 text-white"><LifeBuoy className="size-4" /></span>
          <div>
            <p className="text-[10px] font-semibold text-white">Butuh bantuan?</p>
            <p className="mt-0.5 text-[8px] leading-3.5 text-slate-400">Buka Layanan Pelanggan untuk melihat dan menangani tiket.</p>
          </div>
        </div>
        <button type="button" onClick={onClick} className="mt-3 h-8 w-full rounded-md bg-white/[0.08] text-[9px] font-semibold text-white transition hover:bg-white/[0.13]">Pusat Bantuan</button>
      </div>
    </div>
  );
}
