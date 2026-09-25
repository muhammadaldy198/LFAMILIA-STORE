import { logServerError } from "@/lib/server/safe-log";
import { getD1 } from "@/db";
import { ensureLegacyDatabaseColumns } from "@/lib/server/database-repair";
import { isAutomaticPackageAvailable } from "@/lib/server/availability";
import type { ProductInputField } from "@/lib/store-data";
import { getProviderAdapter } from "@/lib/server/providers";
import type { ProviderAdapter, ProviderResult } from "@/lib/server/providers/types";
import { notifyOrderFulfillmentSuccessById } from "@/lib/server/transaction-notifications";
import { FULFILLMENT_ERROR_TRANSITION_GUARD_SQL } from "@/lib/server/fulfillment-transition-guard.mjs";
import {
  consumeOrderPromotion,
  releaseExternalPromotion,
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
  packageId: number;
  supplierCost: number | null;
  providerMaxPrice: number | null;
  manualOpenTime: string | null;
  manualCloseTime: string | null;
  manualTimezone: string | null;
};

export class CheckoutValidationError extends Error {}

export type OrderRecord = {
  id: string;
  customer_id: string | null;
  wallet_checkout_key: string | null;
  external_checkout_key: string | null;
  reference_id: string;
  product_slug: string;
  product_name: string;
  package_sku: string;
  package_label: string;
  provider_code: string | null;
  provider_sku: string | null;
  fulfillment_type: "automatic" | "manual";
  delivery_mode: "direct" | "voucher" | "manual" | null;
  supplier_cost_snapshot: number | null;
  provider_max_price_snapshot: number | null;
  payment_gateway?: "doku" | "midtrans" | null;
  payment_gateway_mode?: "checkout" | "snap" | null;
  payment_gateway_environment?: "sandbox" | "production" | null;
  gateway_request_id?: string | null;
  gateway_reference_no?: string | null;
  gateway_payment_no?: string | null;
  gateway_qr_content?: string | null;
  gateway_payment_url?: string | null;
  gateway_expired_at?: string | null;
  gateway_status_checked_at?: string | null;
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
  quantity: number;
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
  manual_open_time: string | null;
  manual_close_time: string | null;
  manual_timezone: string | null;
  package_id: number;
  supplier_price: number | null;
  provider_max_price: number | null;
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
  await ensureLegacyDatabaseColumns();
  const db = getD1();
  const row = await db
    .prepare(
      `SELECT p.slug AS product_slug, p.name AS product_name, p.needs_server,
      p.fulfillment_type, p.target_template, p.input_label, p.input_placeholder, p.input_fields_json, p.manual_instructions, p.manual_open_time, p.manual_close_time, p.manual_timezone,
      pp.id AS package_id, pp.sku AS package_sku, pp.label AS package_label, pp.price, pp.provider_code, pp.provider_sku, pp.supplier_price, pp.provider_max_price
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
      providerCode: row.provider_code?.trim().toLowerCase() || null,
      providerSku: row.provider_sku?.trim() || null,
      packageId: row.package_id,
      supplierCost: row.supplier_price,
      providerMaxPrice: row.provider_max_price,
      manualOpenTime: row.manual_open_time,
      manualCloseTime: row.manual_close_time,
      manualTimezone: row.manual_timezone,
    };
  }

  return null;
}

export function renderCustomerNo(
  template: string,
  destination: string,
  server: string | null,
  customerInputs: CustomerInputValue[] = [],
) {
  const tokens = new Map<string, string>([
    ["destination", destination.trim()],
    ["server", server?.trim() ?? ""],
  ]);
  for (const input of customerInputs) {
    tokens.set(input.id.trim().toLowerCase(), input.value.trim());
  }

  const value = template
    .replace(/\{\{([a-z0-9-]+)\}\}/gi, (match, token: string) => {
      const replacement = tokens.get(token.toLowerCase());
      return replacement === undefined ? match : replacement;
    })
    .trim();

  if (!value || value.includes("{{") || value.length > 120)
    throw new CheckoutValidationError(
      "Format tujuan produk belum valid atau masih memiliki data yang belum terisi.",
    );
  return value;
}

function assertManualServiceOpen(item: PurchasableItem) {
  if (item.fulfillmentType !== "manual" || !item.manualOpenTime || !item.manualCloseTime) return;
  const zone = item.manualTimezone || "Asia/Jakarta";
  const time = new Intl.DateTimeFormat("en-GB", { timeZone: zone, hour: "2-digit", minute: "2-digit", hourCycle: "h23" }).format(new Date());
  const [hour, minute] = time.split(":").map(Number); const current = hour * 60 + minute;
  const parse = (value: string) => { const [h, m] = value.split(":").map(Number); return h * 60 + m; };
  const open = parse(item.manualOpenTime), close = parse(item.manualCloseTime);
  const openNow = open === close || (open < close ? current >= open && current < close : current >= open || current < close);
  if (!openNow) throw new CheckoutValidationError("Layanan manual sedang di luar jam operasional.");
}

export function normalizeOrderQuantity(value: unknown) {
  const quantity = Math.trunc(Number(value) || 1);
  if (quantity < 1 || quantity > 10) {
    throw new CheckoutValidationError("Jumlah pembelian harus antara 1 sampai 10.");
  }
  return quantity;
}

function fulfillmentUnitReference(referenceId: string, unitIndex: number) {
  return `${referenceId}-Q${String(unitIndex).padStart(2, "0")}`;
}

export function createOrderIdentity() {
  const id = crypto.randomUUID();
  const date = new Date().toISOString().slice(2, 10).replaceAll("-", "");
  // 56 bits of randomness keeps the customer-facing code short while making
  // collisions impractical; reference_id remains protected by a DB unique key.
  const referenceToken = crypto.randomUUID().replaceAll("-", "").slice(0, 14).toUpperCase();
  return {
    id,
    referenceId: `LF${date}${referenceToken}`,
  };
}

async function assertAutomaticAvailability(item: PurchasableItem) {
  if (item.fulfillmentType !== "automatic") return;
  if (!item.providerCode || !item.providerSku) {
    throw new CheckoutValidationError("Konfigurasi pemrosesan otomatis belum lengkap.");
  }
  if (!await isAutomaticPackageAvailable({
    packageId: item.packageId,
    providerCode: item.providerCode,
    providerSku: item.providerSku,
    maxPrice: item.providerMaxPrice,
  })) {
    throw new CheckoutValidationError("Nominal otomatis sedang tidak tersedia atau harga provider melebihi Max Price.");
  }
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
  paymentGateway?: "doku" | "midtrans" | null;
  paymentGatewayMode?: "checkout" | "snap" | null;
  paymentGatewayEnvironment?: "sandbox" | "production" | null;
  customerId?: string | null;
  walletCheckoutKey?: string | null;
  externalCheckoutKey?: string | null;
  promotion: PromotionQuote;
  quantity?: number;
  adminFee?: number;
}) {
  const db = getD1();
  const quantity = normalizeOrderQuantity(input.quantity);
  if (quantity > 1 && input.item.providerCode === "voucher-stock") {
    throw new CheckoutValidationError("Produk kode voucher hanya dapat dibeli 1 item per pesanan.");
  }
  await assertAutomaticAvailability(input.item);
  if (input.item.fulfillmentType === "automatic") {
    if (!input.item.providerCode || !input.item.providerSku)
      throw new CheckoutValidationError(
        "Konfigurasi pemrosesan otomatis belum lengkap.",
      );
    if (!getProviderAdapter(input.item.providerCode))
      throw new CheckoutValidationError(
        "Sistem pemrosesan otomatis belum tersedia.",
      );
  }
  assertManualServiceOpen(input.item);
  const customerNo = renderCustomerNo(
    input.item.targetTemplate,
    input.destination,
    input.server,
    input.customerInputs,
  );
  const orderStatement = db
    .prepare(
      `INSERT INTO orders (
      id, customer_id, wallet_checkout_key, external_checkout_key, reference_id, product_slug, product_name, package_sku, package_label,
      provider_code, provider_sku, fulfillment_type, delivery_mode, supplier_cost_snapshot, provider_max_price_snapshot, target_template, destination, server,
      nickname, customer_no, buyer_name, buyer_email, buyer_phone, customer_notes, customer_inputs_json, quantity,
      base_subtotal, subtotal, discount_amount, voucher_code, flash_sale_id,
      admin_fee, total, payment_method, payment_channel,
      payment_gateway, payment_gateway_mode, payment_gateway_environment
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)`,
    )
    .bind(
      input.id,
      input.customerId ?? null,
      input.walletCheckoutKey ?? null,
      input.externalCheckoutKey ?? null,
      input.referenceId,
      input.item.productSlug,
      input.item.productName,
      input.item.packageSku,
      input.item.packageLabel,
      input.item.providerCode,
      input.item.providerSku,
      input.item.fulfillmentType,
      input.item.fulfillmentType === "manual" ? "manual" : input.item.providerCode === "voucher-stock" ? "voucher" : "direct",
      input.item.supplierCost == null ? null : input.item.supplierCost * quantity,
      input.item.providerMaxPrice,
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
      quantity,
      input.promotion.basePrice,
      input.promotion.sellingPrice,
      input.promotion.discountAmount,
      input.promotion.voucherCode,
      input.promotion.flashSaleId,
      input.adminFee ?? 0,
      input.promotion.finalPrice + (input.adminFee ?? 0),
      input.paymentMethod,
      input.paymentChannel,
      input.paymentGateway ?? null,
      input.paymentGatewayMode ?? null,
      input.paymentGatewayEnvironment ?? null,
    );

  const statements = [orderStatement];
  if (input.item.fulfillmentType === "automatic" && input.item.providerCode === "digiflazz" && quantity > 1) {
    for (let unitIndex = 1; unitIndex <= quantity; unitIndex += 1) {
      statements.push(
        db.prepare(
          `INSERT INTO order_fulfillment_units (order_id, unit_index, provider_ref_id, provider_status)
           VALUES (?, ?, ?, 'waiting')`,
        ).bind(input.id, unitIndex, fulfillmentUnitReference(input.referenceId, unitIndex)),
      );
    }
  }
  await db.batch(statements);
}

export async function getExternalOrderByCheckoutKey(checkoutKey: string) {
  return getD1()
    .prepare(
      `SELECT * FROM orders
       WHERE external_checkout_key = ? AND payment_method <> 'wallet'
       LIMIT 1`,
    )
    .bind(checkoutKey)
    .first<OrderRecord>();
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
    | "doku"
    | "wallet"
    | "digiflazz"
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

  // Gateway notifications may arrive late or out of order. Only a currently
  // pending invoice may make a payment-state transition; terminal invoices
  // must never be reopened or fulfilled by an old callback.
  if (status === "pending") return false;

  if (status === "paid") {
    const nextFulfillment =
      order.fulfillment_type === "manual" ? "manual_pending" : "processing";
    const result = await db
      .prepare(
        `UPDATE orders SET payment_status = 'paid', fulfillment_status = ?, updated_at = CURRENT_TIMESTAMP
       WHERE id = ? AND payment_status = 'pending'`,
      )
      .bind(nextFulfillment, order.id)
      .run();
    const changed = Number(result.meta.changes ?? 0) > 0;
    if (changed)
      await consumeOrderPromotion(order.voucher_code, order.flash_sale_id, order.id);
    return changed;
  }

  const result = await db
    .prepare(
      `UPDATE orders SET payment_status = ?, updated_at = CURRENT_TIMESTAMP
     WHERE id = ? AND payment_status = 'pending'`,
    )
    .bind(status, order.id)
    .run();
  if (Number(result.meta.changes ?? 0) > 0) {
    await releaseExternalPromotion(order.id);
  }
  return false;
}

type FulfillmentUnitRow = {
  id: number;
  order_id: string;
  unit_index: number;
  provider_ref_id: string;
  provider_status: string;
  provider_message: string | null;
  provider_serial_number: string | null;
  attempts: number;
  updated_at: string;
};

async function refreshMultiUnitOrder(orderId: string) {
  const db = getD1();
  const rows = await db.prepare(
    `SELECT * FROM order_fulfillment_units WHERE order_id = ? ORDER BY unit_index ASC`,
  ).bind(orderId).all<FulfillmentUnitRow>();
  if (!rows.results.length) return;

  const total = rows.results.length;
  const successful = rows.results.filter((unit) => unit.provider_status === "success").length;
  const terminalFailed = rows.results.filter((unit) => ["failed", "retry_exhausted"].includes(unit.provider_status)).length;
  const pending = total - successful - terminalFailed;
  const serials = rows.results.map((unit) => unit.provider_serial_number).filter(Boolean).join(" | ") || null;

  if (successful === total) {
    await db.prepare(
      `UPDATE orders SET fulfillment_status = 'success', provider_status = 'success',
       provider_message = ?, provider_serial_number = ?, updated_at = CURRENT_TIMESTAMP
       WHERE id = ? AND payment_status = 'paid'`,
    ).bind(`${successful}/${total} item berhasil dikirim.`, serials, orderId).run();
    return;
  }

  if (pending === 0 && terminalFailed > 0) {
    await db.prepare(
      `UPDATE orders SET fulfillment_status = 'needs_review', provider_status = 'partial_failed',
       provider_message = ?, provider_serial_number = ?, updated_at = CURRENT_TIMESTAMP
       WHERE id = ? AND payment_status = 'paid'`,
    ).bind(`${successful}/${total} item berhasil; ${terminalFailed} item memerlukan pemeriksaan.`, serials, orderId).run();
    return;
  }

  const retryable = rows.results.some((unit) => unit.provider_status === "retryable_error");
  await db.prepare(
    `UPDATE orders SET fulfillment_status = 'processing', provider_status = ?,
     provider_message = ?, provider_serial_number = ?, updated_at = CURRENT_TIMESTAMP
     WHERE id = ? AND payment_status = 'paid'`,
  ).bind(
    retryable ? "retryable_error" : "dispatching",
    `${successful}/${total} item selesai, sisanya masih diproses.`,
    serials,
    orderId,
  ).run();
}

async function claimFulfillmentUnit(unit: FulfillmentUnitRow) {
  const db = getD1();
  const result = await db.prepare(
    `UPDATE order_fulfillment_units
     SET provider_status = 'dispatching', attempts = attempts + 1, updated_at = CURRENT_TIMESTAMP
     WHERE id = ? AND attempts < 5
       AND (
         provider_status = 'waiting'
         OR (
           provider_status IN ('processing', 'dispatching', 'retryable_error')
           AND updated_at <= datetime('now', '-2 minutes')
         )
       )`,
  ).bind(unit.id).run();
  if (Number(result.meta.changes ?? 0) > 0) return true;

  await db.prepare(
    `UPDATE order_fulfillment_units
     SET provider_status = 'retry_exhausted',
       provider_message = COALESCE(provider_message, 'Pemrosesan gagal setelah 5 percobaan.'),
       updated_at = CURRENT_TIMESTAMP
     WHERE id = ? AND attempts >= 5
       AND provider_status NOT IN ('success', 'failed', 'retry_exhausted')`,
  ).bind(unit.id).run();
  return false;
}

async function fulfillAutomaticOrderUnits(
  order: OrderRecord,
  adapter: ProviderAdapter,
  providerCode: string,
  providerSku: string,
  publicBaseUrl: string,
) {
  const db = getD1();
  const units = await db.prepare(
    `SELECT * FROM order_fulfillment_units WHERE order_id = ? ORDER BY unit_index ASC`,
  ).bind(order.id).all<FulfillmentUnitRow>();

  for (const unit of units.results) {
    if (["success", "failed", "retry_exhausted"].includes(unit.provider_status)) continue;
    if (!await claimFulfillmentUnit(unit)) continue;

    try {
      const result = await adapter.fulfill(
        {
          id: order.id,
          referenceId: unit.provider_ref_id,
          providerCode,
          providerSku,
          destination: order.destination,
          server: order.server,
          customerNo: order.customer_no!,
          customerNotes: order.customer_notes,
          subtotal: Math.max(1, Math.ceil(order.subtotal / Math.max(1, order.quantity))),
          maxProviderPrice: order.provider_max_price_snapshot,
          packageSku: order.package_sku,
          packageLabel: order.package_label,
          productName: order.product_name,
          buyerName: order.buyer_name,
          buyerEmail: order.buyer_email,
          buyerPhone: order.buyer_phone,
        },
        publicBaseUrl,
      );
      await db.prepare(
        `UPDATE order_fulfillment_units
         SET provider_status = ?, provider_message = ?, provider_serial_number = COALESCE(?, provider_serial_number),
           updated_at = CURRENT_TIMESTAMP
         WHERE id = ? AND provider_status NOT IN ('success', 'failed', 'retry_exhausted')`,
      ).bind(result.status, result.message, result.serialNumber, unit.id).run();
      await recordOrderEvent({
        orderId: order.id,
        source: providerCode === "digiflazz" ? "digiflazz" : "admin",
        eventId: `unit-request-${unit.provider_ref_id}-${unit.attempts + 1}`,
        status: result.status,
        payload: result.raw,
      });
    } catch (error) {
      const message = error instanceof Error ? error.message : "Provider gagal dihubungi.";
      await db.prepare(
        `UPDATE order_fulfillment_units SET provider_status = 'retryable_error',
         provider_message = ?, updated_at = CURRENT_TIMESTAMP
         WHERE id = ? AND provider_status NOT IN ('success', 'failed', 'retry_exhausted')`,
      ).bind(message, unit.id).run();
    }
  }

  await refreshMultiUnitOrder(order.id);
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
  const providerCode = order.provider_code?.trim().toLowerCase() || null;
  const providerSku = order.provider_sku?.trim() || null;
  if (!providerCode || !providerSku || !order.customer_no) {
    await setFulfillmentError(
      order.id,
      "Provider, SKU, atau format tujuan belum lengkap.",
    );
    return;
  }
  const adapter = getProviderAdapter(providerCode);
  if (!adapter) {
    await setFulfillmentError(
      order.id,
      `Adapter ${providerCode} belum tersedia.`,
    );
    return;
  }
  const currentItem = await resolvePurchasableItem(order.product_slug, order.package_sku);
  if (
    !currentItem ||
    currentItem.providerCode !== providerCode ||
    currentItem.providerSku !== providerSku ||
    !await isAutomaticPackageAvailable({
      packageId: currentItem?.packageId ?? 0,
      providerCode,
      providerSku,
      maxPrice: order.provider_max_price_snapshot,
    })
  ) {
    await setFulfillmentError(
      order.id,
      "Nominal tidak lagi tersedia atau harga provider melebihi Max Price saat fulfillment.",
    );
    return;
  }

  if (Math.max(1, Number(order.quantity || 1)) > 1) {
    if (providerCode !== "digiflazz") {
      await setFulfillmentError(order.id, "Jumlah pembelian lebih dari 1 hanya didukung untuk produk Digiflazz otomatis.");
      return;
    }
    await fulfillAutomaticOrderUnits(order, adapter, providerCode, providerSku, publicBaseUrl);
    return;
  }

  const claimed = await claimAutomaticFulfillmentAttempt(
    order.id,
    providerCode,
  );
  if (!claimed) return;

  try {
    const result = await adapter.fulfill(
      {
        id: order.id,
        referenceId: order.reference_id,
        providerCode,
        providerSku,
        destination: order.destination,
        server: order.server,
        customerNo: order.customer_no,
        customerNotes: order.customer_notes,
        subtotal: order.subtotal,
        maxProviderPrice: order.provider_max_price_snapshot,
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
    if (providerCode === "digiflazz" || providerCode === "voucher-stock") {
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
        AND lower(trim(provider_code)) IN ('digiflazz', 'voucher-stock')
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
           WHEN lower(trim(provider_code)) = 'digiflazz' THEN COALESCE(provider_ref_id, reference_id)
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
       AND COALESCE(quantity, 1) <= 1
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
       AND COALESCE(quantity, 1) <= 1
       AND provider_status = 'dispatching'
       AND lower(trim(coalesce(provider_code, ''))) NOT IN ('digiflazz', 'voucher-stock')
       AND updated_at <= datetime('now', '-2 minutes')`,
  ).run();
  const result = await db.prepare(
    `SELECT id FROM orders
     WHERE payment_status = 'paid' AND fulfillment_type = 'automatic'
       AND (
         provider_status IS NULL
         OR (
           provider_status = 'dispatching'
           AND lower(trim(provider_code)) IN ('digiflazz', 'voucher-stock')
           AND updated_at <= datetime('now', '-2 minutes')
         )
         OR (
           provider_status = 'retryable_error'
           AND lower(trim(provider_code)) IN ('digiflazz', 'voucher-stock')
           AND updated_at <= datetime('now', '-2 minutes')
         )
       )
       AND (
         COALESCE(quantity, 1) > 1
         OR (
           SELECT COUNT(*) FROM order_events
           WHERE order_id = orders.id AND source = 'admin' AND status = 'dispatching'
         ) < 5
       )
     ORDER BY CASE WHEN provider_status IS NULL THEN 0 ELSE 1 END, updated_at ASC LIMIT ?`,
  ).bind(Math.min(Math.max(limit, 1), 100)).all<{ id: string }>();
  for (const row of result.results) {
    await fulfillAutomaticOrder(row.id, publicBaseUrl);
    await notifyOrderFulfillmentSuccessById(row.id).catch((error) =>
      logServerError("Notifikasi order hasil recovery gagal:", error),
    );
  }
}

function providerTransitionGuard(status: ProviderResult["status"]) {
  if (status === "success") {
    return "payment_status = 'paid' AND fulfillment_type = 'automatic' AND fulfillment_status NOT IN ('success', 'cancelled')";
  }
  if (status === "failed") {
    return "payment_status = 'paid' AND fulfillment_type = 'automatic' AND fulfillment_status NOT IN ('success', 'failed', 'cancelled')";
  }
  return "payment_status = 'paid' AND fulfillment_type = 'automatic' AND fulfillment_status NOT IN ('success', 'failed', 'cancelled') AND COALESCE(provider_status, '') NOT IN ('success', 'failed')";
}

async function applyProviderResult(
  order: OrderRecord,
  result: ProviderResult,
  eventId?: string,
) {
  const db = getD1();
  const updated = await db
    .prepare(
      `UPDATE orders SET provider_ref_id = COALESCE(?, provider_ref_id), provider_status = ?,
     provider_message = ?, provider_serial_number = COALESCE(?, provider_serial_number),
     fulfillment_status = ?, updated_at = CURRENT_TIMESTAMP
     WHERE id = ? AND ${providerTransitionGuard(result.status)}`,
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

  const changed = Number(updated.meta.changes ?? 0) > 0;
  if (!changed) return false;

  await recordOrderEvent({
    orderId: order.id,
    source:
      order.provider_code?.trim().toLowerCase() === "digiflazz"
        ? "digiflazz"
        : order.provider_code?.trim().toLowerCase() === "voucher-stock"
          ? "voucher_stock"
          : "admin",
    eventId: eventId || `request-${order.reference_id}-${Date.now()}`,
    status: result.status,
    payload: result.raw,
  });
  return true;
}

async function setFulfillmentError(orderId: string, message: string) {
  await getD1()
    .prepare(
      `UPDATE orders SET fulfillment_status = 'needs_review', provider_status = 'error',
       provider_message = ?, updated_at = CURRENT_TIMESTAMP
       WHERE id = ? AND ${FULFILLMENT_ERROR_TRANSITION_GUARD_SQL}`,
    )
    .bind(message, orderId)
    .run();
}

async function setRetryableFulfillmentError(orderId: string, message: string) {
  await getD1()
    .prepare(
      `UPDATE orders SET fulfillment_status = 'processing', provider_status = 'retryable_error',
       provider_message = ?, updated_at = CURRENT_TIMESTAMP
       WHERE id = ? AND ${FULFILLMENT_ERROR_TRANSITION_GUARD_SQL}`,
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
  await ensureLegacyDatabaseColumns();
  const db = getD1();
  const source = input.providerCode === "digiflazz" ? "digiflazz" : "admin";

  const unit = await db.prepare(
    `SELECT * FROM order_fulfillment_units WHERE provider_ref_id = ? LIMIT 1`,
  ).bind(input.providerRefId).first<FulfillmentUnitRow>();
  if (unit) {
    const parent = await getOrderById(unit.order_id);
    if (!parent || parent.payment_status !== "paid") return false;
    const results = await db.batch([
      db.prepare(
        `UPDATE order_fulfillment_units
         SET provider_status = ?, provider_message = ?,
           provider_serial_number = COALESCE(?, provider_serial_number),
           updated_at = CURRENT_TIMESTAMP
         WHERE id = ? AND provider_status NOT IN ('success', 'failed', 'retry_exhausted')
           AND NOT EXISTS (
             SELECT 1 FROM order_events WHERE source = ? AND event_id = ?
           )`,
      ).bind(
        input.result.status,
        input.result.message,
        input.result.serialNumber,
        unit.id,
        source,
        input.eventId,
      ),
      db.prepare(
        `INSERT OR IGNORE INTO order_events (order_id, source, event_id, status, payload_json)
         VALUES (?, ?, ?, ?, ?)`,
      ).bind(
        parent.id,
        source,
        input.eventId,
        input.result.status,
        JSON.stringify(input.result.raw),
      ),
    ]);
    await refreshMultiUnitOrder(parent.id);
    return Number(results[0]?.meta.changes ?? 0) > 0 || Number(results[1]?.meta.changes ?? 0) > 0;
  }

  const order = await db
    .prepare(
      `SELECT * FROM orders WHERE lower(trim(provider_code)) = ? AND (provider_ref_id = ? OR reference_id = ?) LIMIT 1`,
    )
    .bind(input.providerCode, input.providerRefId, input.providerRefId)
    .first<OrderRecord>();
  // A provider may only report a transaction this store has already paid for.
  if (!order || order.payment_status !== "paid") return false;

  const guard = providerTransitionGuard(input.result.status);

  // Update first only when the signed event has never been consumed, then
  // persist the event in the same D1 transaction. Replays therefore cannot
  // refresh or downgrade fulfillment state.
  const results = await db.batch([
    db.prepare(
      `UPDATE orders SET provider_ref_id = COALESCE(?, provider_ref_id), provider_status = ?,
       provider_message = ?, provider_serial_number = COALESCE(?, provider_serial_number),
       fulfillment_status = ?, updated_at = CURRENT_TIMESTAMP
       WHERE id = ? AND ${guard}
         AND NOT EXISTS (
           SELECT 1 FROM order_events WHERE source = ? AND event_id = ?
         )`,
    ).bind(
      input.result.externalId,
      input.result.status,
      input.result.message,
      input.result.serialNumber,
      input.result.status,
      order.id,
      source,
      input.eventId,
    ),
    db.prepare(
      `INSERT OR IGNORE INTO order_events (order_id, source, event_id, status, payload_json)
       VALUES (?, ?, ?, ?, ?)`,
    ).bind(
      order.id,
      source,
      input.eventId,
      input.result.status,
      JSON.stringify(input.result.raw),
    ),
  ]);

  const changed = Number(results[0]?.meta.changes ?? 0) > 0;
  const inserted = Number(results[1]?.meta.changes ?? 0) > 0;
  return changed || inserted;
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
