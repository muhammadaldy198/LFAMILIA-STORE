"use client";

import { useCallback, useEffect, useMemo, useRef, useState } from "react";
import {
  Bell,
  CheckCheck,
  CircleAlert,
  CreditCard,
  FileClock,
  LoaderCircle,
  PackageSearch,
  RefreshCw,
} from "lucide-react";

type NotificationKind = "activity" | "order" | "payment" | "provider";

type NotificationItem = {
  id: string;
  kind: NotificationKind;
  title: string;
  detail: string;
  createdAt: string;
  tab: string;
};

type Summary = {
  recentActivities?: Array<{
    id: string;
    adminName: string;
    action: string;
    target: string;
    createdAt: string;
  }>;
  recentOrders?: Array<{
    id: string;
    referenceId: string;
    productName: string;
    buyerName: string;
    paymentStatus: string;
    fulfillmentStatus: string;
    createdAt: string;
  }>;
  attention?: {
    sellerOff?: number;
    outOfStock?: number;
    priceChanged?: number;
    digiflazzPending?: number;
    paymentCallbackFailed?: number;
    manualPending?: number;
  };
  integrations?: {
    digiflazz?: { lastSyncAt?: string | null };
  };
};

const EMPTY_SUMMARY: Summary = {};

export function AdminNotifications({
  sessionId,
  isOwner,
  onNavigate,
}: {
  sessionId: number;
  isOwner: boolean;
  onNavigate: (tab: string) => void;
}) {
  const [open, setOpen] = useState(false);
  const [summary, setSummary] = useState<Summary>(EMPTY_SUMMARY);
  const [readIds, setReadIds] = useState<string[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");
  const containerRef = useRef<HTMLDivElement>(null);
  const storageKey = `lfamilia:admin-notifications:read:${sessionId}`;

  const load = useCallback(async () => {
    try {
      const response = await fetch("/api/panel/summary?range=7d", { cache: "no-store" });
      const payload = (await response.json().catch(() => ({}))) as Summary & { error?: string };
      if (!response.ok) throw new Error(payload.error || "Notifikasi gagal dimuat.");
      setSummary(payload);
      setError("");
    } catch (reason) {
      setError(reason instanceof Error ? reason.message : "Notifikasi gagal dimuat.");
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    const storageTimer = window.setTimeout(() => {
      try {
        const stored = JSON.parse(window.localStorage.getItem(storageKey) || "[]");
        if (Array.isArray(stored)) setReadIds(stored.filter((id): id is string => typeof id === "string"));
      } catch {
        setReadIds([]);
      }
    }, 0);
    return () => window.clearTimeout(storageTimer);
  }, [storageKey]);

  useEffect(() => {
    const initialTimer = window.setTimeout(() => void load(), 0);
    const timer = window.setInterval(() => void load(), 60_000);
    return () => {
      window.clearTimeout(initialTimer);
      window.clearInterval(timer);
    };
  }, [load]);

  useEffect(() => {
    function closeDropdown(event: MouseEvent) {
      if (!containerRef.current?.contains(event.target as Node)) setOpen(false);
    }
    function closeWithEscape(event: KeyboardEvent) {
      if (event.key === "Escape") setOpen(false);
    }
    document.addEventListener("mousedown", closeDropdown);
    document.addEventListener("keydown", closeWithEscape);
    return () => {
      document.removeEventListener("mousedown", closeDropdown);
      document.removeEventListener("keydown", closeWithEscape);
    };
  }, []);

  const notifications = useMemo(() => buildNotifications(summary, isOwner), [summary, isOwner]);
  const readSet = useMemo(() => new Set(readIds), [readIds]);
  const unreadCount = notifications.reduce((count, item) => count + (readSet.has(item.id) ? 0 : 1), 0);

  function saveReadIds(next: string[]) {
    const compact = Array.from(new Set(next)).slice(-300);
    setReadIds(compact);
    window.localStorage.setItem(storageKey, JSON.stringify(compact));
  }

  function markAsRead(id: string) {
    if (!readSet.has(id)) saveReadIds([...readIds, id]);
  }

  function markAllAsRead() {
    saveReadIds([...readIds, ...notifications.map((item) => item.id)]);
  }

  function openItem(item: NotificationItem) {
    markAsRead(item.id);
    setOpen(false);
    onNavigate(item.tab);
  }

  return (
    <div ref={containerRef} className="relative">
      <button
        type="button"
        onClick={() => setOpen((value) => !value)}
        className="relative grid size-8 place-items-center rounded-full text-[#64748b] transition hover:bg-[#f1f5f9] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#155eef]/30"
        aria-label={unreadCount ? `Notifikasi, ${unreadCount} belum dibaca` : "Notifikasi"}
        aria-haspopup="dialog"
        aria-expanded={open}
      >
        <Bell className="size-4" />
        {unreadCount > 0 && (
          <span className="absolute -right-1 -top-1 min-w-4 rounded-full bg-red-500 px-1 text-center text-[8px] font-black leading-4 text-white ring-2 ring-white">
            {unreadCount > 99 ? "99+" : unreadCount}
          </span>
        )}
      </button>

      {open && (
        <section
          role="dialog"
          aria-label="Daftar notifikasi"
          className="absolute right-0 top-10 z-50 w-[min(22rem,calc(100vw-1.5rem))] overflow-hidden rounded-lg border border-[#e2e8f0] bg-white shadow-[0_18px_50px_rgba(15,23,42,0.18)]"
        >
          <div className="flex items-center justify-between border-b border-[#edf0f5] px-3 py-2.5">
            <div>
              <p className="text-[11px] font-black text-[#172033]">Notifikasi</p>
              <p className="mt-0.5 text-[8px] text-[#94a3b8]">{unreadCount} belum dibaca</p>
            </div>
            <div className="flex items-center gap-1">
              <button
                type="button"
                onClick={() => void load()}
                className="grid size-7 place-items-center rounded-md text-[#64748b] hover:bg-[#f1f5f9]"
                aria-label="Muat ulang notifikasi"
              >
                <RefreshCw className="size-3.5" />
              </button>
              {unreadCount > 0 && (
                <button
                  type="button"
                  onClick={markAllAsRead}
                  className="inline-flex h-7 items-center gap-1 rounded-md px-2 text-[8px] font-bold text-[#155eef] hover:bg-[#edf4ff]"
                >
                  <CheckCheck className="size-3.5" />
                  Tandai dibaca
                </button>
              )}
            </div>
          </div>

          <div className="max-h-[min(28rem,70vh)] overflow-y-auto">
            {loading && (
              <div className="flex items-center justify-center px-4 py-10 text-[9px] text-[#94a3b8]">
                <LoaderCircle className="mr-2 size-4 animate-spin" />
                Memuat notifikasi…
              </div>
            )}
            {!loading && error && (
              <div className="m-3 rounded-md border border-red-200 bg-red-50 px-3 py-2 text-[9px] text-red-700">{error}</div>
            )}
            {!loading && !notifications.length && !error && (
              <div className="px-4 py-10 text-center text-[9px] text-[#94a3b8]">Belum ada notifikasi.</div>
            )}
            {notifications.map((item) => {
              const unread = !readSet.has(item.id);
              const Icon = iconFor(item.kind);
              return (
                <button
                  key={item.id}
                  type="button"
                  onClick={() => openItem(item)}
                  className={`flex w-full gap-2.5 border-b border-[#f0f3f7] px-3 py-2.5 text-left transition last:border-0 hover:bg-[#f8fafc] ${unread ? "bg-[#f5f8ff]" : "bg-white"}`}
                >
                  <span className={`mt-0.5 grid size-7 shrink-0 place-items-center rounded-md ${toneFor(item.kind)}`}>
                    <Icon className="size-3.5" />
                  </span>
                  <span className="min-w-0 flex-1">
                    <span className="flex items-start gap-2">
                      <strong className="min-w-0 flex-1 truncate text-[9px] text-[#263247]">{item.title}</strong>
                      {unread && <span className="mt-1 size-1.5 shrink-0 rounded-full bg-[#155eef]" />}
                    </span>
                    <span className="mt-0.5 block line-clamp-2 text-[8px] leading-4 text-[#7c8aa0]">{item.detail}</span>
                    <span className="mt-1 block text-[7px] font-medium text-[#a0aec0]">{formatTimestamp(item.createdAt)}</span>
                  </span>
                </button>
              );
            })}
          </div>
        </section>
      )}
    </div>
  );
}

function buildNotifications(summary: Summary, isOwner: boolean): NotificationItem[] {
  const items: NotificationItem[] = [];

  for (const activity of summary.recentActivities || []) {
    items.push({
      id: `activity:${activity.id}`,
      kind: "activity",
      title: `${activity.adminName} melakukan aktivitas`,
      detail: `${activity.action} — ${activity.target}`,
      createdAt: activity.createdAt,
      tab: "overview",
    });
  }

  for (const order of summary.recentOrders || []) {
    items.push({
      id: `order:${order.id}`,
      kind: "order",
      title: `Pesanan ${order.referenceId}`,
      detail: `${order.buyerName} • ${order.productName} • ${labelStatus(order.fulfillmentStatus)}`,
      createdAt: order.createdAt,
      tab: "orders",
    });
    items.push({
      id: `payment:${order.id}:${order.paymentStatus}`,
      kind: "payment",
      title: `Pembayaran ${labelStatus(order.paymentStatus)}`,
      detail: `${order.referenceId} • ${order.productName}`,
      createdAt: order.createdAt,
      tab: "orders",
    });
  }

  const attention = summary.attention || {};
  const providerCount =
    Number(attention.sellerOff || 0) +
    Number(attention.outOfStock || 0) +
    Number(attention.priceChanged || 0) +
    Number(attention.digiflazzPending || 0);
  const providerTimestamp = summary.integrations?.digiflazz?.lastSyncAt || new Date().toISOString();
  if (providerCount > 0) {
    items.push({
      id: `provider:${attention.sellerOff || 0}:${attention.outOfStock || 0}:${attention.priceChanged || 0}:${attention.digiflazzPending || 0}`,
      kind: "provider",
      title: `${providerCount} perhatian provider`,
      detail: `${attention.sellerOff || 0} seller OFF, ${attention.outOfStock || 0} stok habis, ${attention.priceChanged || 0} harga berubah, ${attention.digiflazzPending || 0} transaksi pending.`,
      createdAt: providerTimestamp,
      tab: isOwner ? "digiflazz" : "orders",
    });
  }

  if (Number(attention.paymentCallbackFailed || 0) > 0) {
    items.push({
      id: `payment-callback:${attention.paymentCallbackFailed}`,
      kind: "payment",
      title: "Callback pembayaran gagal",
      detail: `${attention.paymentCallbackFailed} callback perlu diperiksa.`,
      createdAt: new Date().toISOString(),
      tab: isOwner ? "payments" : "orders",
    });
  }

  if (Number(attention.manualPending || 0) > 0) {
    items.push({
      id: `manual-order:${attention.manualPending}`,
      kind: "order",
      title: "Pesanan manual menunggu",
      detail: `${attention.manualPending} pesanan manual belum diproses.`,
      createdAt: new Date().toISOString(),
      tab: "orders",
    });
  }

  return items
    .sort((left, right) => timestampValue(right.createdAt) - timestampValue(left.createdAt))
    .slice(0, 30);
}

function timestampValue(value: string) {
  const timestamp = Date.parse(value);
  return Number.isFinite(timestamp) ? timestamp : 0;
}

function labelStatus(value: string) {
  return value.replaceAll("_", " ").replace(/\b\w/g, (letter) => letter.toUpperCase());
}

function formatTimestamp(value: string) {
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) return "Baru saja";
  return date.toLocaleString("id-ID", {
    day: "2-digit",
    month: "short",
    hour: "2-digit",
    minute: "2-digit",
  });
}

function iconFor(kind: NotificationKind) {
  if (kind === "order") return FileClock;
  if (kind === "payment") return CreditCard;
  if (kind === "provider") return PackageSearch;
  return CircleAlert;
}

function toneFor(kind: NotificationKind) {
  if (kind === "order") return "bg-blue-50 text-blue-600";
  if (kind === "payment") return "bg-emerald-50 text-emerald-600";
  if (kind === "provider") return "bg-amber-50 text-amber-600";
  return "bg-slate-100 text-slate-600";
}
