import { getD1 } from "@/db";
import { getFallbackProducts } from "@/lib/server/products";
import type { ProductInputField } from "@/lib/store-data";
import { getProviderAdapter } from "@/lib/server/providers";
import type { ProviderResult } from "@/lib/server/providers/types";
import {
  consumeOrderPromotion,
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

export type OrderRecord = {
  id: string;
  customer_id: string | null;
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
  flash_sale_id: number | null;
  admin_fee: number;
  total: number;
  payment_method: string;
  payment_channel: string;
  payment_status: string;
  fulfillment_status: string;
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
    if (field.required !== false && !value) throw new Error(`${field.label} wajib diisi.`);
    if (value.length > 300) throw new Error(`${field.label} terlalu panjang.`);
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

  const fallback = getFallbackProducts().find(
    (item) => item.slug === productSlug,
  );
  const packageItem = fallback?.packages.find((item) => item.id === packageSku);
  if (!fallback || !packageItem) return null;
  return {
    productSlug: fallback.slug,
    productName: fallback.name,
    needsServer: Boolean(fallback.needsServer),
    fulfillmentType: fallback.fulfillmentType,
    targetTemplate: fallback.targetTemplate,
    inputFields: fallback.inputFields ?? [],
    manualInstructions: fallback.manualInstructions ?? null,
    packageSku: packageItem.id,
    packageLabel: packageItem.label,
    price: packageItem.price,
    providerCode: packageItem.providerCode ?? null,
    providerSku: packageItem.providerSku ?? null,
  };
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
    throw new Error("Format tujuan provider belum valid.");
  return value;
}

export function createOrderIdentity() {
  const id = crypto.randomUUID();
  const date = new Date().toISOString().slice(2, 10).replaceAll("-", "");
  return {
    id,
    referenceId: `LF${date}${id.replaceAll("-", "").slice(0, 12).toUpperCase()}`,
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
  promotion: PromotionQuote;
}) {
  const db = getD1();
  if (input.item.fulfillmentType === "automatic") {
    if (!input.item.providerCode || !input.item.providerSku)
      throw new Error(
        "Provider dan SKU produk otomatis belum diatur oleh admin.",
      );
    if (!getProviderAdapter(input.item.providerCode))
      throw new Error(
        `Adapter provider ${input.item.providerCode} belum tersedia.`,
      );
  }
  const customerNo = renderCustomerNo(
    input.item.targetTemplate,
    input.destination,
    input.server,
  );
  await db
    .prepare(
      `INSERT INTO orders (
      id, customer_id, reference_id, product_slug, product_name, package_sku, package_label,
      provider_code, provider_sku, fulfillment_type, target_template, destination, server,
      nickname, customer_no, buyer_name, buyer_email, buyer_phone, customer_notes, customer_inputs_json,
      base_subtotal, subtotal, discount_amount, voucher_code, flash_sale_id,
      admin_fee, total, payment_method, payment_channel
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, ?, ?)`,
    )
    .bind(
      input.id,
      input.customerId ?? null,
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
      input.promotion.flashSaleId,
      input.promotion.finalPrice,
      input.paymentMethod,
      input.paymentChannel,
    )
    .run();
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
  await getD1()
    .prepare(
      `UPDATE orders SET ipaymu_transaction_id = ?, ipaymu_payment_no = ?, ipaymu_payment_name = ?,
       ipaymu_payment_url = ?, ipaymu_expired_at = ?, admin_fee = ?, total = ?, updated_at = CURRENT_TIMESTAMP
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
      input.referenceId,
    )
    .run();
}

export async function markPaymentCreationFailed(
  referenceId: string,
  message: string,
) {
  await getD1()
    .prepare(
      `UPDATE orders SET payment_status = 'failed', provider_message = ?, updated_at = CURRENT_TIMESTAMP
     WHERE reference_id = ? AND payment_status = 'pending'`,
    )
    .bind(message, referenceId)
    .run();
}

export async function recordOrderEvent(input: {
  orderId: string;
  source:
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
    const result = await db
      .prepare(
        `UPDATE orders SET payment_status = 'paid', fulfillment_status = ?, updated_at = CURRENT_TIMESTAMP
       WHERE id = ? AND payment_status <> 'paid'`,
      )
      .bind(nextFulfillment, order.id)
      .run();
    const changed = Number(result.meta.changes ?? 0) > 0;
    if (changed)
      await consumeOrderPromotion(order.voucher_code, order.flash_sale_id);
    return changed;
  }
  await db
    .prepare(
      `UPDATE orders SET payment_status = ?, updated_at = CURRENT_TIMESTAMP
     WHERE id = ? AND payment_status <> 'paid'`,
    )
    .bind(status, order.id)
    .run();
  return false;
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

  if (order.provider_code === "digiflazz" && !order.provider_ref_id) {
    await getD1()
      .prepare("UPDATE orders SET provider_ref_id = ? WHERE id = ?")
      .bind(order.reference_id, order.id)
      .run();
  }

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
    await setFulfillmentError(
      order.id,
      error instanceof Error ? error.message : "Provider gagal dihubungi.",
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
