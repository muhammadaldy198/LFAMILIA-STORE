"use client";

import { useCallback, useEffect, useMemo, useState } from "react";
import {
  CheckCircle2,
  ExternalLink,
  LoaderCircle,
  RefreshCw,
  RotateCcw,
} from "lucide-react";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "@/components/ui/table";
import { formatRupiah } from "@/lib/store-data";

type Order = {
  id: string;
  reference_id: string;
  product_name: string;
  package_label: string;
  destination: string;
  server: string | null;
  buyer_name: string;
  buyer_phone: string;
  customer_inputs_json?: string;
  total: number | null;
  payment_method: string;
  payment_status: string;
  fulfillment_type: "automatic" | "manual";
  fulfillment_status: string;
  provider_code: string | null;
  provider_message: string | null;
  provider_serial_number: string | null;
  delivery_mode: "direct" | "voucher" | "manual";
  created_at: string;
};

export function AdminOrderManager() {
  const [orders, setOrders] = useState<Order[]>([]);
  const [query, setQuery] = useState("");
  const [status, setStatus] = useState("all");
  const [loading, setLoading] = useState(true);
  const [workingId, setWorkingId] = useState<string | null>(null);
  const [serialNumbers, setSerialNumbers] = useState<Record<string, string>>({});
  const [error, setError] = useState("");
  const [role, setRole] = useState<"owner" | "staff">("staff");

  const load = useCallback(async () => {
    setLoading(true);
    setError("");
    try {
      const response = await fetch("/api/panel/orders", { cache: "no-store" });
      const data = (await response.json().catch(() => ({}))) as {
        orders?: Order[];
        role?: "owner" | "staff";
        error?: string;
      };
      if (!response.ok) throw new Error(data.error ?? "Pesanan gagal dimuat.");
      setOrders(data.orders ?? []);
      setRole(data.role ?? "staff");
    } catch (reason) {
      setError(
        reason instanceof Error ? reason.message : "Pesanan gagal dimuat.",
      );
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    let active = true;
    void fetch("/api/panel/orders", { cache: "no-store" })
      .then(async (response) => {
        const data = (await response.json().catch(() => ({}))) as {
          orders?: Order[];
          role?: "owner" | "staff";
          error?: string;
        };
        if (!response.ok)
          throw new Error(data.error ?? "Pesanan gagal dimuat.");
        if (active) {
          setOrders(data.orders ?? []);
          setRole(data.role ?? "staff");
        }
      })
      .catch((reason) => {
        if (active)
          setError(
            reason instanceof Error ? reason.message : "Pesanan gagal dimuat.",
          );
      })
      .finally(() => {
        if (active) setLoading(false);
      });
    return () => {
      active = false;
    };
  }, []);

  const visible = useMemo(() => {
    const term = query.trim().toLowerCase();
    return orders.filter(
      (order) =>
        (status === "all" ||
          order.payment_status === status ||
          order.fulfillment_status === status) &&
        (!term ||
          `${order.reference_id} ${order.product_name} ${order.buyer_name} ${order.destination} ${parseOrderInputs(order).map((item) => item.value).join(" ")}`
            .toLowerCase()
            .includes(term)),
    );
  }, [orders, query, status]);

  async function completeManual(order: Order) {
    const voucherManual = order.delivery_mode === "voucher";
    const serialNumber = serialNumbers[order.id]?.trim() ?? "";
    if (voucherManual && !serialNumber) {
      setError("Kode voucher / serial wajib diisi sebelum pesanan diselesaikan.");
      return;
    }

    setWorkingId(order.id);
    setError("");
    try {
      const response = await fetch("/api/panel/orders", {
        method: "PATCH",
        headers: { "content-type": "application/json" },
        body: JSON.stringify({
          id: order.id,
          action: "complete_manual",
          ...(voucherManual ? { serialNumber } : {}),
        }),
      });
      const data = (await response.json().catch(() => ({}))) as { error?: string };
      if (!response.ok)
        throw new Error(data.error ?? "Pesanan gagal diperbarui.");
      if (voucherManual) {
        setSerialNumbers((current) => {
          const next = { ...current };
          delete next[order.id];
          return next;
        });
      }
      await load();
    } catch (reason) {
      setError(
        reason instanceof Error ? reason.message : "Pesanan gagal diperbarui.",
      );
    } finally {
      setWorkingId(null);
    }
  }

  async function retryVoucher(id: string) {
    setWorkingId(id);
    setError("");
    try {
      const response = await fetch("/api/panel/vouchers", {
        method: "PATCH",
        headers: { "content-type": "application/json" },
        body: JSON.stringify({ action: "retry", orderId: id }),
      });
      const data = (await response.json().catch(() => ({}))) as {
        ok?: boolean;
        message?: string;
        error?: string;
      };
      if (!response.ok || !data.ok)
        throw new Error(
          data.error || data.message || "Pengiriman kode belum berhasil.",
        );
      await load();
    } catch (reason) {
      setError(
        reason instanceof Error ? reason.message : "Pengiriman kode gagal.",
      );
    } finally {
      setWorkingId(null);
    }
  }

  return (
    <div>
      <div className="mb-4 flex flex-col gap-3 sm:flex-row">
        <Input
          value={query}
          onChange={(event) => setQuery(event.target.value)}
          placeholder="Cari invoice, produk, atau pelanggan..."
          className="h-10 rounded-xl border-white/10 bg-white/[0.035] text-white placeholder:text-white/22"
        />
        <select
          value={status}
          onChange={(event) => setStatus(event.target.value)}
          className="h-10 rounded-xl border border-white/10 bg-[#151924] px-3 text-xs text-white"
        >
          <option value="all">Semua status</option>
          <option value="pending">Menunggu bayar</option>
          <option value="paid">Sudah dibayar</option>
          <option value="manual_pending">Manual tertunda</option>
          <option value="processing">Diproses provider</option>
          <option value="success">Selesai</option>
          <option value="needs_review">Perlu ditinjau</option>
        </select>
        <Button
          type="button"
          onClick={() => void load()}
          variant="outline"
          className="shrink-0 rounded-xl border-white/10 bg-white/[0.03] text-white hover:bg-white/[0.08] hover:text-white"
        >
          <RefreshCw className="mr-2 size-4" />
          Muat ulang
        </Button>
      </div>
      {error && (
        <div className="mb-4 rounded-xl border border-red-400/20 bg-red-400/[0.06] p-3 text-xs text-red-200">
          {error}
        </div>
      )}
      {loading ? (
        <div className="flex min-h-40 items-center justify-center text-xs text-white/35">
          <LoaderCircle className="mr-2 size-4 animate-spin" />
          Memuat pesanan…
        </div>
      ) : visible.length === 0 ? (
        <div className="rounded-xl border border-dashed border-white/10 p-10 text-center text-xs text-white/30">
          Belum ada pesanan yang cocok.
        </div>
      ) : (
        <div className="overflow-x-auto rounded-xl border border-white/[0.08]">
          <Table>
            <TableHeader>
              <TableRow className="border-white/[0.08] hover:bg-transparent">
                <TableHead className="text-[10px] text-white/35">
                  Invoice
                </TableHead>
                <TableHead className="text-[10px] text-white/35">
                  Produk
                </TableHead>
                <TableHead className="text-[10px] text-white/35">
                  Pelanggan
                </TableHead>
                <TableHead className="text-[10px] text-white/35">
                  Total
                </TableHead>
                <TableHead className="text-[10px] text-white/35">
                  Pembayaran
                </TableHead>
                <TableHead className="text-[10px] text-white/35">
                  Pemenuhan
                </TableHead>
                <TableHead className="text-right text-[10px] text-white/35">
                  Aksi
                </TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {visible.map((order) => {
                const manualReady =
                  order.fulfillment_type === "manual" &&
                  order.payment_status === "paid" &&
                  order.fulfillment_status === "manual_pending";
                const voucherManualReady =
                  manualReady && order.delivery_mode === "voucher";
                const voucherRetry =
                  role === "owner" &&
                  order.provider_code === "voucher-stock" &&
                  order.payment_status === "paid" &&
                  order.fulfillment_status !== "success";
                const wa = order.buyer_phone
                  .replace(/\D/g, "")
                  .replace(/^0/, "62");
                return (
                  <TableRow
                    key={order.id}
                    className="border-white/[0.07] hover:bg-white/[0.025]"
                  >
                    <TableCell>
                      <strong className="text-[10px] text-white">
                        {order.reference_id}
                      </strong>
                      <p className="mt-1 text-[8px] text-white/25">
                        {order.created_at}
                      </p>
                    </TableCell>
                    <TableCell>
                      <strong className="text-xs">{order.product_name}</strong>
                      <p className="mt-1 text-[9px] text-white/35">
                        {order.package_label}
                      </p>
                      <div className="mt-1 space-y-0.5">{parseOrderInputs(order).map((field) => <p key={field.label} className="text-[9px] text-[#cfff72]"><span className="text-white/30">{field.label}: </span>{field.value || "-"}</p>)}</div>
                    </TableCell>
                    <TableCell>
                      <span className="text-xs text-white/55">
                        {order.buyer_name}
                      </span>
                      <a
                        href={`https://wa.me/${wa}`}
                        target="_blank"
                        rel="noreferrer"
                        className="mt-1 flex items-center gap-1 text-[9px] text-[#cfff72]"
                      >
                        WhatsApp <ExternalLink className="size-2.5" />
                      </a>
                    </TableCell>
                    <TableCell className="text-xs text-[#d8ff8d]">
                      {order.total === null
                        ? "Khusus Pemilik"
                        : formatRupiah(order.total)}
                    </TableCell>
                    <TableCell>
                      <StatusBadge value={order.payment_status} />
                    </TableCell>
                    <TableCell>
                      <StatusBadge value={order.fulfillment_status} />
                      <p className="mt-1 max-w-40 truncate text-[8px] text-white/25">
                        {order.provider_code || "manual"}
                        {order.provider_message
                          ? ` • ${order.provider_message}`
                          : ""}
                      </p>
                      {order.delivery_mode === "voucher" && order.provider_serial_number && (
                        <div className="mt-2 rounded-md border border-[#b9ff35]/15 bg-[#b9ff35]/[0.05] px-2 py-1.5 text-left">
                          <span className="block text-[8px] font-bold uppercase tracking-wider text-[#cfff72]">
                            Kode voucher
                          </span>
                          <span className="mt-0.5 block break-all font-mono text-[9px] text-white/75">
                            {order.provider_serial_number}
                          </span>
                        </div>
                      )}
                    </TableCell>
                    <TableCell className="text-right">
                      <div className="flex flex-col items-end gap-2">
                        {voucherManualReady && (
                          <Input
                            value={serialNumbers[order.id] ?? ""}
                            onChange={(event) =>
                              setSerialNumbers((current) => ({
                                ...current,
                                [order.id]: event.target.value,
                              }))
                            }
                            placeholder="Kode voucher / serial"
                            autoComplete="off"
                            className="h-8 w-44 rounded-lg border-[#b9ff35]/20 bg-[#b9ff35]/[0.04] font-mono text-[9px] text-white placeholder:font-sans placeholder:text-white/25"
                          />
                        )}
                        <div className="flex justify-end gap-1">
                          {manualReady && (
                            <Button
                              type="button"
                              disabled={
                                workingId === order.id ||
                                (voucherManualReady && !(serialNumbers[order.id]?.trim()))
                              }
                              onClick={() => void completeManual(order)}
                              size="sm"
                              className="rounded-lg bg-[#b9ff35] text-[9px] font-black text-[#091006] hover:bg-[#d0ff75] disabled:opacity-45"
                            >
                              {workingId === order.id ? (
                                <LoaderCircle className="size-3 animate-spin" />
                              ) : (
                                <>
                                  <CheckCircle2 className="mr-1 size-3" />
                                  {voucherManualReady ? "Kirim & Selesaikan" : "Selesai"}
                                </>
                              )}
                            </Button>
                          )}
                          {voucherRetry && (
                            <Button
                              type="button"
                              disabled={workingId === order.id}
                              onClick={() => void retryVoucher(order.id)}
                              size="sm"
                              className="rounded-lg bg-amber-300 text-[9px] font-black text-[#171006] hover:bg-amber-200"
                            >
                              {workingId === order.id ? (
                                <LoaderCircle className="size-3 animate-spin" />
                              ) : (
                                <>
                                  <RotateCcw className="mr-1 size-3" />
                                  Kirim kode
                                </>
                              )}
                            </Button>
                          )}
                        </div>
                      </div>
                    </TableCell>
                  </TableRow>
                );
              })}
            </TableBody>
          </Table>
        </div>
      )}
    </div>
  );
}

function StatusBadge({ value }: { value: string }) {
  const labels: Record<string, string> = {
    pending: "Menunggu",
    paid: "Lunas",
    expired: "Kedaluwarsa",
    failed: "Gagal",
    waiting_payment: "Menunggu bayar",
    manual_pending: "Antrean manual",
    processing: "Diproses",
    success: "Selesai",
    needs_review: "Perlu ditinjau",
  };
  const success = value === "paid" || value === "success";
  const warning =
    value === "pending" ||
    value === "waiting_payment" ||
    value === "manual_pending" ||
    value === "processing";
  return (
    <span
      className={`rounded-full px-2 py-1 text-[9px] font-bold ${success ? "bg-[#b9ff35]/10 text-[#d8ff8d]" : warning ? "bg-amber-400/10 text-amber-200" : "bg-red-400/10 text-red-200"}`}
    >
      {labels[value] ?? value}
    </span>
  );
}


function parseOrderInputs(order: Order) {
  try {
    const parsed = JSON.parse(order.customer_inputs_json || "[]") as Array<{ label?: string; value?: string }>;
    if (Array.isArray(parsed) && parsed.length) {
      return parsed
        .filter((item) => item && typeof item.label === "string")
        .map((item) => ({ label: item.label || "Data", value: item.value || "" }));
    }
  } catch {
    // Legacy fallback below.
  }
  return [
    { label: "Data akun", value: order.destination },
    ...(order.server ? [{ label: "Server / Zone", value: order.server }] : []),
  ];
}
