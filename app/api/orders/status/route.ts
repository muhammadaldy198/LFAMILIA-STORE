import { z } from "zod";
import { getD1 } from "@/db";
import { publicPaymentLabel } from "@/lib/public-payment";
import { ensureLegacyDatabaseColumns } from "@/lib/server/database-repair";
import { queryDokuQrisStatus } from "@/lib/server/doku";
import { applyPendingDokuPaymentStatus } from "@/lib/server/doku-payment-transition";
import { getWebsiteVoucherCodeByReference } from "@/lib/server/customer-voucher-codes";
import {
  fulfillAutomaticOrder,
  getOrderById,
  markDokuStatusChecked,
  recordOrderEvent,
  type OrderRecord,
} from "@/lib/server/orders";
import { getPublicBaseUrl } from "@/lib/server/runtime-env";
import { allowRequest, rejectCrossOriginMutation } from "@/lib/server/security";

export const dynamic = "force-dynamic";

const legacyReference = /^LF-\d{8}-[A-F0-9]{8,12}$/;
const compactReference = /^LF\d{6}(?:[A-F0-9]{12}|[A-F0-9]{32})$/;
const publicReference = /^LF(?:[A-F0-9]{8}|[A-F0-9]{12})$/;

const schema = z.object({
  referenceId: z.string().trim().toUpperCase().refine(
    (value) =>
      legacyReference.test(value) ||
      compactReference.test(value) ||
      publicReference.test(value),
    "Format invoice tidak valid.",
  ),
});

function publicReferenceId(value: string) {
  if (compactReference.test(value) || publicReference.test(value)) return value;
  const token = value.split("-").at(-1) ?? value;
  return `LF${token}`;
}

async function resolveOrder(referenceId: string) {
  await ensureLegacyDatabaseColumns();
  const db = getD1();
  const exact = await db.prepare("SELECT * FROM orders WHERE reference_id = ? LIMIT 1")
    .bind(referenceId)
    .first<OrderRecord>();
  if (exact) return exact;

  if (publicReference.test(referenceId)) {
    const token = referenceId.slice(2);
    return db.prepare("SELECT * FROM orders WHERE reference_id LIKE ? LIMIT 1")
      .bind(`%-${token}`)
      .first<OrderRecord>();
  }

  return null;
}

function maskDestination(value: string, server: string | null) {
  const trimmed = value.trim();
  const visible = trimmed.length <= 6
    ? `${trimmed.slice(0, 2)}${"•".repeat(Math.max(trimmed.length - 3, 1))}${trimmed.slice(-1)}`
    : `${trimmed.slice(0, 3)}${"•".repeat(Math.min(trimmed.length - 5, 8))}${trimmed.slice(-2)}`;
  return server ? `${visible} (${server})` : visible;
}

function shouldQueryQris(order: OrderRecord) {
  if (
    order.payment_status !== "pending" ||
    order.payment_method !== "qris" ||
    !order.doku_reference_no
  ) return false;
  const created = Date.parse(order.created_at);
  if (Number.isFinite(created) && Date.now() - created < 60_000) return false;
  const last = order.doku_status_checked_at
    ? Date.parse(order.doku_status_checked_at)
    : 0;
  return !Number.isFinite(last) || Date.now() - last >= 60_000;
}

function publicEventSource(source: string) {
  if (source === "doku") return "payment";
  if (source === "digiflazz") return "processing";
  if (source === "wallet") return "balance";
  if (source === "voucher_stock") return "delivery";
  return source === "admin" ? "admin" : "system";
}

async function expirePendingInvoice(order: OrderRecord) {
  if (order.payment_status !== "pending" || !order.doku_expired_at) return order;
  const expiresAt = Date.parse(order.doku_expired_at);
  if (!Number.isFinite(expiresAt) || expiresAt > Date.now()) return order;
  await applyPendingDokuPaymentStatus(order, "expired");
  return (await getOrderById(order.id)) ?? order;
}

async function refreshQrisStatus(order: OrderRecord) {
  if (!shouldQueryQris(order) || !order.doku_reference_no) return order;
  try {
    await markDokuStatusChecked(order.reference_id);
    const query = await queryDokuQrisStatus({
      referenceId: order.reference_id,
      referenceNo: order.doku_reference_no,
    });
    await recordOrderEvent({
      orderId: order.id,
      source: "doku",
      eventId: `qris-status-${query.requestId}`,
      status: query.status,
      payload: query.raw,
    });
    if (
      query.status === "paid" &&
      Number.isFinite(query.amount) &&
      query.amount === order.total
    ) {
      const firstPaid = await applyPendingDokuPaymentStatus(order, "paid");
      if (firstPaid && order.fulfillment_type === "automatic") {
        await fulfillAutomaticOrder(order.id, getPublicBaseUrl());
      }
    } else if (query.status === "failed") {
      await applyPendingDokuPaymentStatus(order, "failed");
    }
    return (await getOrderById(order.id)) ?? order;
  } catch {
    return (await getOrderById(order.id)) ?? order;
  }
}

export async function POST(request: Request) {
  const originBlock = rejectCrossOriginMutation(request);
  if (originBlock) return originBlock;
  const rate = await allowRequest(request, "order-status", 60, 600);
  if (!rate.allowed) return Response.json({ error: "Terlalu banyak pengecekan transaksi. Coba lagi beberapa menit." }, { status: 429, headers: { "Retry-After": String(rate.retryAfter) } });
  try {
    const { referenceId } = schema.parse(await request.json());
    let order = await resolveOrder(referenceId);
    if (!order) {
      return Response.json({ error: "Invoice tidak ditemukan." }, { status: 404 });
    }

    order = await refreshQrisStatus(order);
    order = await expirePendingInvoice(order);

    const voucherCode = order.payment_status === "paid"
      ? await getWebsiteVoucherCodeByReference(order.reference_id).catch(() => null)
      : null;
    const isInternalVoucherDestination = order.destination.trim() === "00000000";
    const eventsResult = await getD1().prepare(
      `SELECT source, status, created_at FROM order_events
       WHERE order_id = ? ORDER BY created_at ASC, id ASC LIMIT 50`,
    ).bind(order.id).all<{ source: string; status: string; created_at: string }>();

    const events = [
      { source: "system", status: "created", createdAt: order.created_at },
      ...eventsResult.results.map((event) => ({
        source: publicEventSource(event.source),
        status: event.status,
        createdAt: event.created_at,
      })),
    ];

    return Response.json({
      order: {
        referenceId: publicReferenceId(order.reference_id),
        productName: order.product_name,
        packageLabel: order.package_label,
        destination: isInternalVoucherDestination ? null : maskDestination(order.destination, order.server),
        total: order.total,
        paymentMethod: order.payment_method,
        paymentChannel: order.payment_channel,
        paymentStatus: order.payment_status,
        fulfillmentStatus: order.fulfillment_status,
        fulfillmentType: order.fulfillment_type,
        paymentNo: order.payment_status === "pending" ? order.doku_payment_no : null,
        qrContent: order.payment_status === "pending" ? order.doku_qr_content : null,
        paymentName: publicPaymentLabel(order.payment_method, order.payment_channel),
        paymentUrl: order.payment_status === "pending" ? order.doku_payment_url : null,
        expiredAt: order.payment_status === "pending" ? order.doku_expired_at : null,
        voucherCode,
        createdAt: order.created_at,
        updatedAt: order.updated_at,
        events,
      },
    }, { headers: { "Cache-Control": "no-store" } });
  } catch (error) {
    if (error instanceof z.ZodError) {
      return Response.json({ error: "Format invoice tidak valid." }, { status: 400 });
    }
    return Response.json({ error: "Status pesanan belum dapat dimuat." }, { status: 503 });
  }
}
