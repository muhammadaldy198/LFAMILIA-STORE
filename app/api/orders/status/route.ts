import { z } from "zod";
import { getD1 } from "@/db";
import { publicPaymentLabel } from "@/lib/public-payment";
import { ensureLegacyDatabaseColumns } from "@/lib/server/database-repair";
import { queryDokuCheckoutStatus } from "@/lib/server/doku-checkout-status";
import { applyPendingExternalPaymentStatus } from "@/lib/server/payment-transition";
import { getWebsiteVoucherCodeByReference } from "@/lib/server/customer-voucher-codes";
import { externalArtifactsFromOrder, recordExternalPaymentEvent } from "@/lib/server/external-payments";
import { queryMidtransSnapStatus } from "@/lib/server/midtrans-snap";
import {
  fulfillAutomaticOrder,
  getOrderById,
  type OrderRecord,
} from "@/lib/server/orders";
import { getPublicBaseUrl } from "@/lib/server/runtime-env";
import { allowRequest, rejectCrossOriginMutation } from "@/lib/server/security";
import { notifyOrderFulfillmentSuccessById } from "@/lib/server/transaction-notifications";

export const dynamic = "force-dynamic";

const legacyReference = /^LF-\d{8}-[A-F0-9]{8,12}$/;
const compactReference = /^LF\d{6}(?:[A-F0-9]{14}|[A-F0-9]{32})$/;
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

function externalArtifacts(order: OrderRecord) {
  return externalArtifactsFromOrder(order as unknown as Record<string, unknown>);
}

function gatewayMetadata(order: OrderRecord) {
  const raw = order as unknown as Record<string, unknown>;
  const environment = raw.payment_gateway_environment === "production"
    ? "production"
    : raw.payment_gateway_environment === "sandbox"
      ? "sandbox"
      : null;
  const lastCheckedAt = typeof raw.gateway_status_checked_at === "string"
    ? raw.gateway_status_checked_at
    : null;
  return { environment, lastCheckedAt } as const;
}

function maskDestination(value: string, server: string | null) {
  const trimmed = value.trim();
  const visible = trimmed.length <= 6
    ? `${trimmed.slice(0, 2)}${"•".repeat(Math.max(trimmed.length - 3, 1))}${trimmed.slice(-1)}`
    : `${trimmed.slice(0, 3)}${"•".repeat(Math.min(trimmed.length - 5, 8))}${trimmed.slice(-2)}`;
  return server ? `${visible} (${server})` : visible;
}

function oldEnough(order: OrderRecord, minimumMs: number) {
  const created = Date.parse(order.created_at);
  return !Number.isFinite(created) || Date.now() - created >= minimumMs;
}

function dueForGatewayCheck(order: OrderRecord, minimumMs: number) {
  if (!oldEnough(order, minimumMs)) return false;
  const { lastCheckedAt } = gatewayMetadata(order);
  const last = lastCheckedAt ? Date.parse(lastCheckedAt) : 0;
  return !Number.isFinite(last) || Date.now() - last >= minimumMs;
}

function shouldQueryDoku(order: OrderRecord) {
  const artifacts = externalArtifacts(order);
  const metadata = gatewayMetadata(order);
  return artifacts.gateway === "doku" &&
    order.payment_status === "pending" &&
    Boolean(metadata.environment) &&
    artifacts.mode === "checkout" && dueForGatewayCheck(order, 3_000);
}

function shouldQueryMidtransSnap(order: OrderRecord) {
  const artifacts = externalArtifacts(order);
  const metadata = gatewayMetadata(order);
  return artifacts.gateway === "midtrans" &&
    artifacts.mode === "snap" &&
    order.payment_status === "pending" &&
    Boolean(metadata.environment) &&
    dueForGatewayCheck(order, 3_000);
}

async function markGatewayStatusChecked(referenceId: string) {
  await getD1().prepare(
    `UPDATE orders
     SET gateway_status_checked_at = CURRENT_TIMESTAMP
     WHERE reference_id = ? AND payment_status = 'pending'`,
  ).bind(referenceId).run();
}

function publicEventSource(source: string) {
  if (source === "doku" || source === "midtrans") return "payment";
  if (source === "digiflazz") return "processing";
  if (source === "wallet") return "balance";
  if (source === "voucher_stock") return "delivery";
  return source === "admin" ? "admin" : "system";
}

async function expirePendingInvoice(order: OrderRecord) {
  if (order.payment_status !== "pending") return order;
  const artifacts = externalArtifacts(order);
  if (!artifacts.expiredAt) return order;
  const expiresAt = Date.parse(artifacts.expiredAt);
  if (!Number.isFinite(expiresAt) || expiresAt > Date.now()) return order;

  await applyPendingExternalPaymentStatus(order, "expired");
  return (await getOrderById(order.id)) ?? order;
}

async function queryDokuOrderStatus(order: OrderRecord) {
  const artifacts = externalArtifacts(order);
  const { environment } = gatewayMetadata(order);
  if (!environment) return null;

  if (artifacts.mode !== "checkout") return null;
  return queryDokuCheckoutStatus({ referenceId: order.reference_id, environment });
}

async function refreshDokuStatus(order: OrderRecord) {
  if (!shouldQueryDoku(order)) return order;
  try {
    await markGatewayStatusChecked(order.reference_id);
    const query = await queryDokuOrderStatus(order);
    if (!query) return (await getOrderById(order.id)) ?? order;

    await recordExternalPaymentEvent({
      orderId: order.id,
      gateway: "doku",
      eventId: `status-query-${query.requestId}-${query.status}`,
      status: query.status,
      payload: query.raw,
    });

    if (query.status === "paid") {
      if (!Number.isFinite(query.amount) || query.amount !== order.total) {
        return (await getOrderById(order.id)) ?? order;
      }
      const firstPaid = await applyPendingExternalPaymentStatus(order, "paid");
      if (firstPaid && order.fulfillment_type === "automatic") {
        await fulfillAutomaticOrder(order.id, getPublicBaseUrl());
        await notifyOrderFulfillmentSuccessById(order.id).catch((error) =>
          console.error("Notifikasi pesanan DOKU hasil rekonsiliasi gagal:", error),
        );
      }
    } else if (query.status === "expired") {
      await applyPendingExternalPaymentStatus(order, "expired");
    }

    return (await getOrderById(order.id)) ?? order;
  } catch {
    return (await getOrderById(order.id)) ?? order;
  }
}

async function refreshMidtransSnapStatus(order: OrderRecord) {
  if (!shouldQueryMidtransSnap(order)) return order;
  const { environment } = gatewayMetadata(order);
  if (!environment) return order;

  try {
    await markGatewayStatusChecked(order.reference_id);
    const query = await queryMidtransSnapStatus({
      orderId: order.reference_id,
      environment,
    });
    const eventSuffix = query.transactionId || order.reference_id;
    await recordExternalPaymentEvent({
      orderId: order.id,
      gateway: "midtrans",
      eventId: `snap-status-${eventSuffix}-${query.status}`,
      status: query.status,
      payload: query.raw,
    });

    if (query.status === "paid") {
      if (!Number.isFinite(query.amount) || query.amount !== order.total) {
        return (await getOrderById(order.id)) ?? order;
      }
      const firstPaid = await applyPendingExternalPaymentStatus(order, "paid");
      if (firstPaid && order.fulfillment_type === "automatic") {
        await fulfillAutomaticOrder(order.id, getPublicBaseUrl());
        await notifyOrderFulfillmentSuccessById(order.id).catch((error) =>
          console.error("Notifikasi pesanan Midtrans hasil rekonsiliasi gagal:", error),
        );
      }
    } else if (query.status === "failed" || query.status === "expired") {
      await applyPendingExternalPaymentStatus(order, query.status);
    }

    return (await getOrderById(order.id)) ?? order;
  } catch {
    return (await getOrderById(order.id)) ?? order;
  }
}

async function recoverPaidAutomaticFulfillment(order: OrderRecord) {
  if (order.payment_status !== "paid" || order.fulfillment_type !== "automatic") return order;
  if (order.fulfillment_status === "success" || order.fulfillment_status === "failed" || order.fulfillment_status === "cancelled") return order;

  await fulfillAutomaticOrder(order.id, getPublicBaseUrl());
  await notifyOrderFulfillmentSuccessById(order.id).catch((error) =>
    console.error("Notifikasi pesanan hasil recovery fulfillment gagal:", error),
  );
  return (await getOrderById(order.id)) ?? order;
}

export async function POST(request: Request) {
  const originBlock = rejectCrossOriginMutation(request);
  if (originBlock) return originBlock;
  // Payment page polls every 3 seconds. Keep enough headroom for automatic polling
  // plus manual refresh without allowing unbounded public status reads.
  const rate = await allowRequest(request, "order-status", 240, 600);
  if (!rate.allowed) return Response.json({ error: "Terlalu banyak pengecekan transaksi. Coba lagi beberapa menit." }, { status: 429, headers: { "Retry-After": String(rate.retryAfter) } });
  try {
    const { referenceId } = schema.parse(await request.json());
    let order = await resolveOrder(referenceId);
    if (!order) {
      return Response.json({ error: "Invoice tidak ditemukan." }, { status: 404 });
    }

    order = await refreshDokuStatus(order);
    order = await refreshMidtransSnapStatus(order);
    order = await expirePendingInvoice(order);
    order = await recoverPaidAutomaticFulfillment(order);

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
    const artifacts = externalArtifacts(order);
    const pending = order.payment_status === "pending";

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
        paymentNo: pending ? artifacts.paymentNo : null,
        qrContent: pending ? artifacts.qrContent : null,
        paymentName: publicPaymentLabel(order.payment_method, order.payment_channel),
        paymentUrl: pending ? artifacts.paymentUrl : null,
        expiredAt: pending ? artifacts.expiredAt : null,
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
