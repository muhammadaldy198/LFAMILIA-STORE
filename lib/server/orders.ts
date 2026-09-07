import { getD1 } from "@/db";
import type { ProductInputField } from "@/lib/store-data";
import { ensureLegacyDatabaseColumns } from "@/lib/server/database-repair";
import { getProviderAdapter } from "@/lib/server/providers";
import type { ProviderResult } from "@/lib/server/providers/types";
import { notifyOrderFulfillmentSuccessById } from "@/lib/server/transaction-notifications";
import {
  PromotionQuoteError,
  type PromotionQuote,
} from "@/lib/server/promotions";

export type PurchasableItem = {
  productSlug: string;
  productName: string;
  needsServer: boolean;
  fulfillmentType: "automatic" | "manual";
  targetTemplate: string;
  inputFields: ProductInputField[];
  manualInstructions: string | null;
  packageSku: string;
  packageLabel: string;
  price: number;
  providerCode: string | null;
  providerSku: string | null;
};

export class CheckoutValidationError extends Error {}

export type OrderRecord = {
  id: string;
  customer_id: string | null;
  wallet_checkout_key: string | null;
  reference_id: string;
  product_slug: string;
  product_name: string;
  package_sku: string;
  package_label: string;
  provider_code: string | null;
  provider_sku: string | null;
  fulfillment_type: "automatic" | "manual";
  target_template: string;
  destination: string;
  server: string | null;
  nickname: string | null;
  customer_no: string | null;
  buyer_name: string;
  buyer_email: string;
  buyer_phone: string;
  customer_notes: string | null;
  customer_inputs_json: string;
  base_subtotal: number;
  subtotal: number;
  discount_amount: number;
  voucher_code: string | null;
  voucher_id: number | null;
  flash_sale_id: number | null;
  promotion_reservation_status: "none" | "legacy" | "reserved" | "consumed" | "released";
  promotion_reserved_until: string | null;
  admin_fee: number;
  total: number;
  payment_method: string;
  payment_channel: string;
  payment_status: string;
  fulfillment_status: string;
  midtrans_transaction_id: string | null;
  midtrans_payment_no: string | null;
  midtrans_payment_name: string | null;
  midtrans_payment_url: string | null;
  midtrans_expired_at: string | null;
  midtrans_mode: string | null;
  ipaymu_transaction_id: string | null;
  ipaymu_payment_no: string | null;
  ipaymu_payment_name: string | null;
  ipaymu_payment_url: string | null;
  ipaymu_expired_at: string | null;
  provider_ref_id: string | null;
  provider_status: string | null;
  provider_message: string | null;
  provider_serial_number: string | null;
  created_at: string;
  updated_at: string;
};

type StoredItemRow = {
  product_slug: string;
  product_name: string;
  needs_server: number;
  fulfillment_type: "automatic" | "manual";
  target_template: string;
  input_label: string;
  input_placeholder: string;
  input_fields_json: string | null;
  manual_instructions: string | null;
  package_sku: string;
  package_label: string;
  price: number;
  provider_code: string | null;
  provider_sku: string | null;
};

function parseProductInputFields(
  value: string | null,
  inputLabel: string,
  inputPlaceholder: string,
  needsServer: boolean,
): ProductInputField[] {
  if (value !== null) {
    try {
      const parsed = JSON.parse(value) as unknown;
      if (Array.isArray(parsed)) {
        return parsed
          .filter((item): item is Record<string, unknown> => Boolean(item) && typeof item === "object")
          .map((item, index) => ({
            id: typeof item.id === "string" && item.id.trim() ? item.id.trim() : `field-${index + 1}`,
            label: typeof item.label === "string" ? item.label.trim() : "",
            placeholder: typeof item.placeholder === "string" ? item.placeholder.trim() : "",
            required: item.required !== false,
          }))
          .filter((item) => item.label)
          .slice(0, 12);
      }
    } catch {
      // Legacy fallback below.
    }
  }
  return [
    { id: "account-id", label: inputLabel, placeholder: inputPlaceholder, required: true },
    ...(needsServer ? [{ id: "server-zone", label: "Server / Zone ID", placeholder: "Contoh: 1234", required: true }] : []),
  ];
}

export type CustomerInputValue = {
  id: string;
  label: string;
  value: string;
};

export function normalizeCustomerInputs(
  item: PurchasableItem,
  submitted: Array<{ id: string; value: string }> | undefined,
  legacyDestination = "",
  legacyServer: string | null = null,
) {
  const byId = new Map((submitted ?? []).map((entry) => [entry.id, entry.value.trim()]));
  const values = item.inputFields.map((field, index) => {
    const fallback = index === 0 ? legacyDestination.trim() : index === 1 ? legacyServer?.trim() ?? "" : "";
    const value = (byId.get(field.id) ?? fallback).trim();
    if (field.required !== false && !value) throw new CheckoutValidationError(`${field.label} wajib diisi.`);
    if (value.length > 300) throw new CheckoutValidationError(`${field.label} terlalu panjang.`);
    return { id: field.id, label: field.label, value };
  });
  return {
    values,
    destination: values[0]?.value || "-",
    server: values[1]?.value || null,
  };
}

export async function resolvePurchasableItem(
  productSlug: string,
  packageSku: string,
): Promise<PurchasableItem | null> {
  const db = getD1();
  const row = await db
    .prepare(
      `SELECT p.slug AS product_slug, p.name AS product_name, p.needs_server,
      p.fulfillment_type, p.target_template, p.input_label, p.input_placeholder, p.input_fields_json, p.manual_instructions,
      pp.sku AS package_sku, pp.label AS package_label, pp.price, pp.provider_code, pp.provider_sku
     FROM products p
     JOIN product_packages pp ON pp.product_id = p.id
     WHERE p.slug = ? AND pp.sku = ? AND p.is_active = 1 AND pp.is_active = 1
     LIMIT 1`,
    )
    .bind(productSlug, packageSku)
    .first<StoredItemRow>();
  if (row) {
    return {
      productSlug: row.product_slug,
      productName: row.product_name,
      needsServer: Boolean(row.needs_server),
      fulfillmentType: row.fulfillment_type,
      targetTemplate: row.target_template,
      inputFields: parseProductInputFields(row.input_fields_json, row.input_label, row.input_placeholder, Boolean(row.needs_server)),
      manualInstructions: row.manual_instructions,
      packageSku: row.package_sku,
      packageLabel: row.package_label,
      price: row.price,
      providerCode: row.provider_code,
      providerSku: row.provider_sku,
    };
  }

  return null;
}

export function renderCustomerNo(
  template: string,
  destination: string,
  server: string | null,
) {
  const value = template
    .replaceAll("{{destination}}", destination.trim())
    .replaceAll("{{server}}", server?.trim() ?? "")
    .trim();
  if (!value || value.includes("{{") || value.length > 120)
    throw new CheckoutValidationError("Format tujuan provider belum valid.");
  return value;
}

export function createOrderIdentity() {
  const id = crypto.randomUUID();
  const date = new Date().toISOString().slice(2, 10).replaceAll("-", "");
  const referenceToken = crypto.randomUUID().replaceAll("-", "").toUpperCase();
  return {
    id,
    referenceId: `LF${date}${referenceToken}`,
  };
}

export async function insertPendingOrder(input: {
  id: string;
  referenceId: string;
  item: PurchasableItem;
  destination: string;
  server: string | null;
  nickname: string | null;
  buyerName: string;
  buyerEmail: string;
  buyerPhone: string;
  customerNotes: string | null;
  customerInputs: CustomerInputValue[];
  paymentMethod: string;
  paymentChannel: string;
  customerId?: string | null;
  walletCheckoutKey?: string | null;
  promotion: PromotionQuote;
}) {
  const db = getD1();
  if (input.item.fulfillmentType === "automatic") {
    if (!input.item.providerCode || !input.item.providerSku)
      throw new CheckoutValidationError(
        "Provider dan SKU produk otomatis belum diatur oleh admin.",
      );
    if (!getProviderAdapter(input.item.providerCode))
      throw new CheckoutValidationError(
        `Adapter provider ${input.item.providerCode} belum tersedia.`,
      );
  }
  const customerNo = renderCustomerNo(
    input.item.targetTemplate,
    input.destination,
    input.server,
  );
  const now = new Date().toISOString();
  const reservePromotion =
    input.paymentMethod !== "wallet" &&
    Boolean(input.promotion.voucherCode || input.promotion.flashSaleId);
  const reservationStatus = reservePromotion ? "reserved" : "none";
  const reservationLease = reservePromotion
    ? new Date(Date.now() + 10 * 60_000).toISOString()
    : null;
  const results = await db.batch([
    db.prepare(
      `INSERT INTO orders (
      id, customer_id, wallet_checkout_key, reference_id, product_slug, product_name, package_sku, package_label,
      provider_code, provider_sku, fulfillment_type, target_template, destination, server,
      nickname, customer_no, buyer_name, buyer_email, buyer_phone, customer_notes, customer_inputs_json,
      base_subtotal, subtotal, discount_amount, voucher_code, voucher_id, flash_sale_id,
      promotion_reservation_status, promotion_reserved_until,
      admin_fee, total, payment_method, payment_channel
    ) SELECT ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, ?, ?
      WHERE (? IS NULL OR EXISTS (
        SELECT 1 FROM discount_vouchers
        WHERE id = ? AND is_active = 1 AND starts_at <= ? AND ends_at >= ?
          AND (usage_limit IS NULL OR used_count < usage_limit)
      ))
      AND (? IS NULL OR EXISTS (
        SELECT 1 FROM flash_sales
        WHERE id = ? AND is_active = 1 AND starts_at <= ? AND ends_at >= ?
          AND (stock_limit IS NULL OR sold_count < stock_limit)
      ))`,
    )
    .bind(
      input.id,
      input.customerId ?? null,
      input.walletCheckoutKey ?? null,
      input.referenceId,
      input.item.productSlug,
      input.item.productName,
      input.item.packageSku,
      input.item.packageLabel,
      input.item.providerCode,
      input.item.providerSku,
      input.item.fulfillmentType,
      input.item.targetTemplate,
      input.destination,
      input.server,
      input.nickname,
      customerNo,
      input.buyerName,
      input.buyerEmail,
      input.buyerPhone,
      input.customerNotes,
      JSON.stringify(input.customerInputs),
      input.promotion.basePrice,
      input.promotion.sellingPrice,
      input.promotion.discountAmount,
      input.promotion.voucherCode,
      input.promotion.voucherId,
      input.promotion.flashSaleId,
      reservationStatus,
      reservationLease,
      input.promotion.finalPrice,
      input.paymentMethod,
      input.paymentChannel,
      reservePromotion ? input.promotion.voucherId : null,
      input.promotion.voucherId,
      now,
      now,
      reservePromotion ? input.promotion.flashSaleId : null,
      input.promotion.flashSaleId,
      now,
      now,
    ),
    db.prepare(
      `UPDATE discount_vouchers SET used_count = used_count + 1, updated_at = CURRENT_TIMESTAMP
       WHERE id = ? AND is_active = 1 AND starts_at <= ? AND ends_at >= ?
         AND (usage_limit IS NULL OR used_count < usage_limit)
         AND EXISTS (
           SELECT 1 FROM orders
           WHERE id = ? AND promotion_reservation_status = 'reserved'
         )`,
    ).bind(
      reservePromotion ? input.promotion.voucherId : null,
      now,
      now,
      input.id,
    ),
    db.prepare(
      `UPDATE flash_sales SET sold_count = sold_count + 1, updated_at = CURRENT_TIMESTAMP
       WHERE id = ? AND is_active = 1 AND starts_at <= ? AND ends_at >= ?
         AND (stock_limit IS NULL OR sold_count < stock_limit)
         AND EXISTS (
           SELECT 1 FROM orders
           WHERE id = ? AND promotion_reservation_status = 'reserved'
         )`,
    ).bind(
      reservePromotion ? input.promotion.flashSaleId : null,
      now,
      now,
      input.id,
    ),
  ]);
  if (Number(results[0]?.meta.changes ?? 0) === 0) {
    throw new PromotionQuoteError(
      "Voucher atau kuota flash sale baru saja habis. Muat ulang checkout.",
    );
  }
}

export async function getWalletOrderByCheckoutKey(
  customerId: string,
  checkoutKey: string,
) {
  return getD1()
    .prepare(
      `SELECT * FROM orders
       WHERE customer_id = ? AND wallet_checkout_key = ? AND payment_method = 'wallet'
       LIMIT 1`,
    )
    .bind(customerId, checkoutKey)
    .first<OrderRecord>();
}

export async function getOrderByReference(referenceId: string) {
  return getD1()
    .prepare("SELECT * FROM orders WHERE reference_id = ? LIMIT 1")
    .bind(referenceId)
    .first<OrderRecord>();
}

export async function getOrderById(id: string) {
  return getD1()
    .prepare("SELECT * FROM orders WHERE id = ? LIMIT 1")
    .bind(id)
    .first<OrderRecord>();
}

export async function updateMidtransPayment(input: {
  referenceId: string;
  mode: "snap" | "bisnap";
  transactionId: string | null;
  paymentNo: string | null;
  paymentName: string | null;
  paymentUrl: string | null;
  expiredAt: string | null;
  fee: number;
  total: number;
}) {
  const reservationExpiry = paymentReservationExpiry(input.expiredAt);
  await getD1()
    .prepare(
      `UPDATE orders SET midtrans_transaction_id = ?, midtrans_payment_no = ?,
       midtrans_payment_name = ?, midtrans_payment_url = ?, midtrans_expired_at = ?,
       midtrans_mode = ?, admin_fee = ?, total = ?,
       promotion_reserved_until = CASE
         WHEN promotion_reservation_status = 'reserved' THEN ?
         ELSE promotion_reserved_until
       END,
       updated_at = CURRENT_TIMESTAMP
       WHERE reference_id = ?`,
    )
    .bind(
      input.transactionId,
      input.paymentNo,
      input.paymentName,
      input.paymentUrl,
      input.expiredAt,
      input.mode,
      input.fee,
      input.total,
      reservationExpiry,
      input.referenceId,
    )
    .run();
}

export async function updateIpaymuPayment(input: {
  referenceId: string;
  transactionId: string | null;
  paymentNo: string | null;
  paymentName: string | null;
  paymentUrl: string | null;
  expiredAt: string | null;
  fee: number;
  total: number;
}) {
  const reservationExpiry = paymentReservationExpiry(input.expiredAt);
  await getD1()
    .prepare(
      `UPDATE orders SET ipaymu_transaction_id = ?, ipaymu_payment_no = ?, ipaymu_payment_name = ?,
       ipaymu_payment_url = ?, ipaymu_expired_at = ?, admin_fee = ?, total = ?,
       promotion_reserved_until = CASE
         WHEN promotion_reservation_status = 'reserved' THEN ?
         ELSE promotion_reserved_until
       END,
       updated_at = CURRENT_TIMESTAMP
       WHERE reference_id = ?`,
    )
    .bind(
      input.transactionId,
      input.paymentNo,
      input.paymentName,
      input.paymentUrl,
      input.expiredAt,
      input.fee,
      input.total,
      reservationExpiry,
      input.referenceId,
    )
    .run();
}

function paymentReservationExpiry(expiredAt: string | null) {
  const parsed = expiredAt ? Date.parse(expiredAt) : Number.NaN;
  return Number.isFinite(parsed)
    ? new Date(parsed).toISOString()
    : new Date(Date.now() + 24 * 60 * 60_000).toISOString();
}

export async function markPaymentCreationFailed(
  referenceId: string,
  message: string,
) {
  const db = getD1();
  await db.batch([
    db.prepare(
      `UPDATE discount_vouchers SET used_count = MAX(0, used_count - 1), updated_at = CURRENT_TIMESTAMP
       WHERE id = (
         SELECT voucher_id FROM orders
         WHERE reference_id = ? AND payment_status = 'pending'
           AND promotion_reservation_status = 'reserved'
       )`,
    ).bind(referenceId),
    db.prepare(
      `UPDATE flash_sales SET sold_count = MAX(0, sold_count - 1), updated_at = CURRENT_TIMESTAMP
       WHERE id = (
         SELECT flash_sale_id FROM orders
         WHERE reference_id = ? AND payment_status = 'pending'
           AND promotion_reservation_status = 'reserved'
       )`,
    ).bind(referenceId),
    db.prepare(
      `UPDATE orders SET payment_status = 'failed', provider_message = ?,
         promotion_reservation_status = CASE
           WHEN promotion_reservation_status = 'reserved' THEN 'released'
           ELSE promotion_reservation_status
         END,
         updated_at = CURRENT_TIMESTAMP
       WHERE reference_id = ? AND payment_status = 'pending'`,
    ).bind(message, referenceId),
  ]);
}

export async function recordOrderEvent(input: {
  orderId: string;
  source:
    | "midtrans"
    | "ipaymu"
    | "wallet"
    | "digiflazz"
    | "vippayment"
    | "voucher_stock"
    | "admin";
  eventId: string;
  status: string;
  payload: unknown;
}) {
  return getD1()
    .prepare(
      `INSERT OR IGNORE INTO order_events (order_id, source, event_id, status, payload_json)
     VALUES (?, ?, ?, ?, ?)`,
    )
    .bind(
      input.orderId,
      input.source,
      input.eventId,
      input.status,
      JSON.stringify(input.payload),
    )
    .run();
}

export async function applyPaymentStatus(
  order: OrderRecord,
  status: "paid" | "pending" | "expired" | "failed",
) {
  const db = getD1();
  if (status === "paid") {
    const nextFulfillment =
      order.fulfillment_type === "manual" ? "manual_pending" : "processing";
    const results = await db.batch([
      db.prepare(
        `UPDATE discount_vouchers SET used_count = used_count + 1, updated_at = CURRENT_TIMESTAMP
         WHERE id = COALESCE(?, (SELECT id FROM discount_vouchers WHERE code = ?)) AND EXISTS (
           SELECT 1 FROM orders WHERE id = ? AND payment_status <> 'paid'
             AND promotion_reservation_status IN ('legacy', 'released')
         )`,
      ).bind(order.voucher_id, order.voucher_code, order.id),
      db.prepare(
        `UPDATE flash_sales SET sold_count = sold_count + 1, updated_at = CURRENT_TIMESTAMP
         WHERE id = ? AND EXISTS (
           SELECT 1 FROM orders WHERE id = ? AND payment_status <> 'paid'
             AND promotion_reservation_status IN ('legacy', 'released')
         )`,
      ).bind(order.flash_sale_id, order.id),
      db.prepare(
        `UPDATE orders SET payment_status = 'paid', fulfillment_status = ?,
           promotion_reservation_status = CASE
             WHEN voucher_code IS NOT NULL OR flash_sale_id IS NOT NULL THEN 'consumed'
             ELSE 'none'
           END,
           updated_at = CURRENT_TIMESTAMP
         WHERE id = ? AND payment_status <> 'paid'`,
      ).bind(nextFulfillment, order.id),
    ]);
    return Number(results[2]?.meta.changes ?? 0) > 0;
  }
  if (status === "pending") return false;
  await db.batch([
    db.prepare(
      `UPDATE discount_vouchers SET used_count = MAX(0, used_count - 1), updated_at = CURRENT_TIMESTAMP
       WHERE id = COALESCE(?, (SELECT id FROM discount_vouchers WHERE code = ?)) AND EXISTS (
         SELECT 1 FROM orders WHERE id = ? AND payment_status = 'pending'
           AND promotion_reservation_status = 'reserved'
       )`,
    ).bind(order.voucher_id, order.voucher_code, order.id),
    db.prepare(
      `UPDATE flash_sales SET sold_count = MAX(0, sold_count - 1), updated_at = CURRENT_TIMESTAMP
       WHERE id = ? AND EXISTS (
         SELECT 1 FROM orders WHERE id = ? AND payment_status = 'pending'
           AND promotion_reservation_status = 'reserved'
       )`,
    ).bind(order.flash_sale_id, order.id),
    db.prepare(
      `UPDATE orders SET payment_status = ?,
         promotion_reservation_status = CASE
           WHEN promotion_reservation_status = 'reserved' THEN 'released'
           ELSE promotion_reservation_status
         END,
         updated_at = CURRENT_TIMESTAMP
       WHERE id = ? AND payment_status = 'pending'`,
    ).bind(status, order.id),
  ]);
  return false;
}

const expiredReservationCondition = `payment_status = 'pending'
  AND promotion_reservation_status = 'reserved'
  AND promotion_reserved_until IS NOT NULL
  AND promotion_reserved_until <= ?`;

export async function releaseExpiredPromotionReservation(
  orderId: string,
  now = new Date().toISOString(),
) {
  const db = getD1();
  const results = await db.batch([
    db.prepare(
      `UPDATE discount_vouchers SET used_count = MAX(0, used_count - 1), updated_at = CURRENT_TIMESTAMP
       WHERE id = (
         SELECT voucher_id FROM orders WHERE id = ? AND ${expiredReservationCondition}
       )`,
    ).bind(orderId, now),
    db.prepare(
      `UPDATE flash_sales SET sold_count = MAX(0, sold_count - 1), updated_at = CURRENT_TIMESTAMP
       WHERE id = (
         SELECT flash_sale_id FROM orders WHERE id = ? AND ${expiredReservationCondition}
       )`,
    ).bind(orderId, now),
    db.prepare(
      `UPDATE orders SET payment_status = 'failed', promotion_reservation_status = 'released',
         provider_message = CASE
           WHEN midtrans_transaction_id IS NOT NULL OR midtrans_payment_url IS NOT NULL
             OR ipaymu_transaction_id IS NOT NULL OR ipaymu_payment_url IS NOT NULL
           THEN 'Masa pembayaran berakhir tanpa callback sukses.'
           ELSE 'Checkout terputus sebelum pembayaran berhasil dibuat.'
         END,
         updated_at = CURRENT_TIMESTAMP
       WHERE id = ? AND ${expiredReservationCondition}`,
    ).bind(orderId, now),
  ]);
  return Number(results[2]?.meta.changes ?? 0) > 0;
}

export async function recoverExpiredPromotionReservations(limit = 100) {
  await ensureLegacyDatabaseColumns();
  const now = new Date().toISOString();
  const result = await getD1().prepare(
    `SELECT id FROM orders WHERE ${expiredReservationCondition}
     ORDER BY promotion_reserved_until ASC LIMIT ?`,
  ).bind(now, Math.min(Math.max(limit, 1), 500)).all<{ id: string }>();
  let released = 0;
  for (const row of result.results) {
    if (await releaseExpiredPromotionReservation(row.id, now)) released += 1;
  }
  return released;
}

export async function fulfillAutomaticOrder(
  orderId: string,
  publicBaseUrl: string,
) {
  const order = await getOrderById(orderId);
  if (
    !order ||
    order.payment_status !== "paid" ||
    order.fulfillment_type !== "automatic"
  )
    return;
  if (!order.provider_code || !order.provider_sku || !order.customer_no) {
    await setFulfillmentError(
      order.id,
      "Provider, SKU, atau format tujuan belum lengkap.",
    );
    return;
  }
  const adapter = getProviderAdapter(order.provider_code);
  if (!adapter) {
    await setFulfillmentError(
      order.id,
      `Adapter ${order.provider_code} belum tersedia.`,
    );
    return;
  }

  const claimed = await claimAutomaticFulfillmentAttempt(
    order.id,
    order.provider_code,
  );
  if (!claimed) return;

  try {
    const result = await adapter.fulfill(
      {
        id: order.id,
        referenceId: order.reference_id,
        providerCode: order.provider_code,
        providerSku: order.provider_sku,
        destination: order.destination,
        server: order.server,
        customerNo: order.customer_no,
        customerNotes: order.customer_notes,
        subtotal: order.subtotal,
        packageSku: order.package_sku,
        packageLabel: order.package_label,
        productName: order.product_name,
        buyerName: order.buyer_name,
        buyerEmail: order.buyer_email,
        buyerPhone: order.buyer_phone,
      },
      publicBaseUrl,
    );
    await applyProviderResult(order, result);
  } catch (error) {
    const message = error instanceof Error ? error.message : "Provider gagal dihubungi.";
    if (order.provider_code === "digiflazz" || order.provider_code === "voucher-stock") {
      await setRetryableFulfillmentError(order.id, message);
    } else {
      await setFulfillmentError(order.id, message);
    }
  }
}

export async function claimAutomaticFulfillmentAttempt(
  orderId: string,
  providerCode: string,
) {
  const db = getD1();
  const eventId = `fulfillment-attempt-${orderId}-${crypto.randomUUID()}`;
  const eligible = `payment_status = 'paid' AND fulfillment_type = 'automatic'
    AND (
      provider_status IS NULL
      OR (
        provider_status IN ('dispatching', 'retryable_error')
        AND provider_code IN ('digiflazz', 'voucher-stock')
        AND updated_at <= datetime('now', '-2 minutes')
      )
    )`;
  const results = await db.batch([
    db.prepare(
      `INSERT INTO order_events (order_id, source, event_id, status, payload_json)
       SELECT id, 'admin', ?, 'dispatching', ? FROM orders
       WHERE id = ? AND ${eligible}
         AND (
           SELECT COUNT(*) FROM order_events
           WHERE order_id = orders.id AND source = 'admin' AND status = 'dispatching'
         ) < 5`,
    ).bind(eventId, JSON.stringify({ providerCode }), orderId),
    db.prepare(
      `UPDATE orders SET fulfillment_status = 'dispatching', provider_status = 'dispatching',
         provider_ref_id = CASE
           WHEN provider_code = 'digiflazz' THEN COALESCE(provider_ref_id, reference_id)
           ELSE provider_ref_id
         END,
         updated_at = CURRENT_TIMESTAMP
       WHERE id = ? AND EXISTS (
         SELECT 1 FROM order_events WHERE source = 'admin' AND event_id = ?
       )`,
    ).bind(orderId, eventId),
    db.prepare(
      `UPDATE orders SET fulfillment_status = 'needs_review', provider_status = 'retry_exhausted',
         provider_message = 'Pemenuhan otomatis gagal setelah 5 percobaan; periksa sebelum mencoba ulang.',
         updated_at = CURRENT_TIMESTAMP
       WHERE id = ? AND ${eligible}
         AND NOT EXISTS (
           SELECT 1 FROM order_events WHERE source = 'admin' AND event_id = ?
         )
         AND (
           SELECT COUNT(*) FROM order_events
           WHERE order_id = orders.id AND source = 'admin' AND status = 'dispatching'
         ) >= 5`,
    ).bind(orderId, eventId),
  ]);
  return Number(results[0]?.meta.changes ?? 0) > 0;
}

export async function recoverStaleAutomaticOrders(
  publicBaseUrl: string,
  limit = 20,
) {
  const db = getD1();
  await db.prepare(
    `UPDATE orders SET fulfillment_status = 'needs_review', provider_status = 'retry_exhausted',
       provider_message = 'Pemenuhan otomatis gagal setelah 5 percobaan; periksa sebelum mencoba ulang.',
       updated_at = CURRENT_TIMESTAMP
     WHERE payment_status = 'paid' AND fulfillment_type = 'automatic'
       AND provider_status IN ('dispatching', 'retryable_error')
       AND (
         SELECT COUNT(*) FROM order_events
         WHERE order_id = orders.id AND source = 'admin' AND status = 'dispatching'
       ) >= 5`,
  ).run();
  await db.prepare(
    `UPDATE orders SET fulfillment_status = 'needs_review', provider_status = 'unknown',
       provider_message = 'Hasil pengiriman provider belum dapat dipastikan; periksa sebelum mencoba ulang.',
       updated_at = CURRENT_TIMESTAMP
     WHERE payment_status = 'paid' AND fulfillment_type = 'automatic'
       AND provider_status = 'dispatching'
       AND provider_code NOT IN ('digiflazz', 'voucher-stock')
       AND updated_at <= datetime('now', '-2 minutes')`,
  ).run();
  const result = await db.prepare(
    `SELECT id FROM orders
     WHERE payment_status = 'paid' AND fulfillment_type = 'automatic'
       AND (
         provider_status IS NULL
         OR (
           provider_status = 'dispatching'
           AND provider_code IN ('digiflazz', 'voucher-stock')
           AND updated_at <= datetime('now', '-2 minutes')
         )
         OR (
           provider_status = 'retryable_error'
           AND provider_code IN ('digiflazz', 'voucher-stock')
           AND updated_at <= datetime('now', '-2 minutes')
         )
       )
       AND (
         SELECT COUNT(*) FROM order_events
         WHERE order_id = orders.id AND source = 'admin' AND status = 'dispatching'
       ) < 5
     ORDER BY CASE WHEN provider_status IS NULL THEN 0 ELSE 1 END, updated_at ASC LIMIT ?`,
  ).bind(Math.min(Math.max(limit, 1), 100)).all<{ id: string }>();
  for (const row of result.results) {
    await fulfillAutomaticOrder(row.id, publicBaseUrl);
    await notifyOrderFulfillmentSuccessById(row.id).catch((error) =>
      console.error("Notifikasi order hasil recovery gagal:", error),
    );
  }
}

async function applyProviderResult(
  order: OrderRecord,
  result: ProviderResult,
  eventId?: string,
) {
  const db = getD1();
  await db
    .prepare(
      `UPDATE orders SET provider_ref_id = COALESCE(?, provider_ref_id), provider_status = ?,
     provider_message = ?, provider_serial_number = COALESCE(?, provider_serial_number),
     fulfillment_status = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?`,
    )
    .bind(
      result.externalId,
      result.status,
      result.message,
      result.serialNumber,
      result.status,
      order.id,
    )
    .run();
  await recordOrderEvent({
    orderId: order.id,
    source:
      order.provider_code === "digiflazz"
        ? "digiflazz"
        : order.provider_code === "vippayment"
          ? "vippayment"
          : order.provider_code === "voucher-stock"
            ? "voucher_stock"
            : "admin",
    eventId: eventId || `request-${order.reference_id}-${Date.now()}`,
    status: result.status,
    payload: result.raw,
  });
}

async function setFulfillmentError(orderId: string, message: string) {
  await getD1()
    .prepare(
      `UPDATE orders SET fulfillment_status = 'needs_review', provider_status = 'error',
     provider_message = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?`,
    )
    .bind(message, orderId)
    .run();
}

async function setRetryableFulfillmentError(orderId: string, message: string) {
  await getD1()
    .prepare(
      `UPDATE orders SET fulfillment_status = 'processing', provider_status = 'retryable_error',
       provider_message = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?`,
    )
    .bind(message, orderId)
    .run();
}

export async function applyProviderWebhook(input: {
  providerCode: string;
  providerRefId: string;
  eventId: string;
  result: ProviderResult;
}) {
  const db = getD1();
  const order = await db
    .prepare(
      `SELECT * FROM orders WHERE provider_code = ? AND (provider_ref_id = ? OR reference_id = ?) LIMIT 1`,
    )
    .bind(input.providerCode, input.providerRefId, input.providerRefId)
    .first<OrderRecord>();
  if (!order) return false;
  await applyProviderResult(order, input.result, input.eventId);
  return true;
}

export async function listOrders(limit = 200) {
  const result = await getD1()
    .prepare(`SELECT * FROM orders ORDER BY created_at DESC LIMIT ?`)
    .bind(Math.min(Math.max(limit, 1), 500))
    .all<OrderRecord>();
  return result.results;
}

export async function completeManualOrder(id: string, adminEmail: string) {
  const db = getD1();
  const order = await getOrderById(id);
  if (
    !order ||
    order.fulfillment_type !== "manual" ||
    order.payment_status !== "paid"
  ) {
    throw new Error("Pesanan manual belum lunas atau tidak ditemukan.");
  }
  await db
    .prepare(
      `UPDATE orders SET fulfillment_status = 'success', provider_status = 'manual_done',
     provider_message = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?`,
    )
    .bind(`Diselesaikan oleh ${adminEmail}`, id)
    .run();
  await recordOrderEvent({
    orderId: id,
    source: "admin",
    eventId: `manual-${id}-${Date.now()}`,
    status: "success",
    payload: { adminEmail },
  });
}

export async function confirmManualOrderPayment(
  id: string,
  publicBaseUrl: string,
) {
  const order = await getOrderById(id);
  if (!order) throw new Error("Pesanan tidak ditemukan.");
  if (!order.payment_method.startsWith("manual_"))
    throw new Error("Pesanan ini bukan pembayaran manual.");
  const firstPaid = await applyPaymentStatus(order, "paid");
  if (firstPaid) {
    await recordOrderEvent({
      orderId: order.id,
      source: "admin",
      eventId: `manual-payment-${order.id}`,
      status: "paid",
      payload: { method: order.payment_method },
    });
    if (order.fulfillment_type === "automatic")
      await fulfillAutomaticOrder(order.id, publicBaseUrl);
  }
  return firstPaid;
}
