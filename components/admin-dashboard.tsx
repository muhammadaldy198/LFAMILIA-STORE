"use client";

import { useEffect, useRef, useState, type FormEvent, type MouseEvent as ReactMouseEvent } from "react";
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
import { AdminDigiflazzWorkspace } from "@/components/admin-digiflazz-workspace";
import { AdminExperienceManager } from "@/components/admin-experience-manager";
import { AdminIntegrationWorkspace } from "@/components/admin-integration-workspace";
import { AdminOrderManager } from "@/components/admin-order-manager";
import { AdminOverview } from "@/components/admin-overview";
import { AdminPaymentWorkspace } from "@/components/admin-payment-workspace";
import { AdminProductManager } from "@/components/admin-product-manager";
import { AdminCustomerWorkspace } from "@/components/admin-customer-workspace";
import { adminActionEvent } from "@/components/admin-workspace-ui";
import { AdminPromoWorkspace, AdminReportsWorkspace, AdminSettingsWorkspace, AdminSupportWorkspace, AdminTeamWorkspace } from "@/components/admin-operations-workspaces";

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
  { value: "support", label: "Layanan Pelanggan", Icon: Headphones },
  { value: "reports", label: "Laporan", Icon: BarChart3, ownerOnly: true },
  { value: "team", label: "Staff & Admin Akses", Icon: UserCog, ownerOnly: true },
  { value: "integrations", label: "Integrasi", Icon: Layers3, ownerOnly: true },
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
  const [uiNotice, setUiNotice] = useState("");
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

  useEffect(() => {
    function showAction(event: Event) {
      const message = (event as CustomEvent<string>).detail || "Aksi frontend dijalankan.";
      setUiNotice(message);
      window.setTimeout(() => setUiNotice(""), 1800);
    }
    window.addEventListener(adminActionEvent, showAction);
    return () => window.removeEventListener(adminActionEvent, showAction);
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

  function confirmUiAction(event: ReactMouseEvent<HTMLDivElement>) {
    const button = (event.target as HTMLElement).closest("button");
    if (!button || button.disabled) return;
    const label =
      button.getAttribute("aria-label") ||
      button.getAttribute("title") ||
      button.textContent?.trim();
    if (!label || label === "Tersimpan") return;
    setUiNotice(`${label.replace(/\s+/g, " ").slice(0, 70)} aktif.`);
    window.setTimeout(() => setUiNotice(""), 1800);
  }

  return (
    <Tabs
      value={activeTab}
      onValueChange={setActiveTab}
      onClick={confirmUiAction}
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
        {uiNotice && <div className="fixed right-5 top-[70px] z-[100] rounded-md border border-emerald-200 bg-emerald-50 px-4 py-2 text-[10px] font-bold text-emerald-700 shadow-lg">{uiNotice}</div>}
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

            {isOwner && <TabsContent value="digiflazz" className="mt-0"><AdminDigiflazzWorkspace /></TabsContent>}

            {isOwner && <TabsContent value="payments" className="mt-0"><AdminPaymentWorkspace /></TabsContent>}
            {isOwner && <TabsContent value="customers" className="mt-0"><AdminCustomerWorkspace /></TabsContent>}
            {isOwner && <TabsContent value="promotions" className="mt-0"><AdminPromoWorkspace /></TabsContent>}
            <TabsContent value="support" className="mt-0"><AdminSupportWorkspace /></TabsContent>
            {isOwner && <TabsContent value="reports" className="mt-0"><AdminReportsWorkspace /></TabsContent>}
            {isOwner && <TabsContent value="team" className="mt-0"><AdminTeamWorkspace /></TabsContent>}
            {isOwner && <TabsContent value="integrations" className="mt-0"><AdminIntegrationWorkspace /></TabsContent>}
            {isOwner && <TabsContent value="settings" className="mt-0"><AdminSettingsWorkspace /></TabsContent>}
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
