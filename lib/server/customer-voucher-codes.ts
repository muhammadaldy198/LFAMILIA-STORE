import { getD1 } from "@/db";
import {
  listCustomerVoucherCodes,
  revealVoucherCode,
} from "@/lib/server/vouchers";

type ProviderVoucherRow = {
  id: string;
  reference_id: string;
  product_name: string;
  package_label: string;
  provider_serial_number: string;
  updated_at: string;
};

type WebsiteVoucherOrderRow = {
  id: string;
  reference_id: string;
  payment_status: string;
  provider_code: string | null;
  provider_serial_number: string | null;
};

function stableNumericId(value: string) {
  let hash = 2166136261;
  for (let index = 0; index < value.length; index += 1) {
    hash ^= value.charCodeAt(index);
    hash = Math.imul(hash, 16777619);
  }
  return Math.abs(hash | 0) + 1_000_000_000;
}

function voucherModeSql() {
  return "LOWER(TRIM(p.category)) = 'voucher'";
}

export async function listCustomerWebsiteVoucherCodes(customerId: string) {
  const db = getD1();
  const [stockCodes, eligibleResult, providerResult] = await Promise.all([
    listCustomerVoucherCodes(customerId).catch(() => []),
    db.prepare(
      `SELECT o.reference_id
       FROM orders o
       JOIN products p ON p.slug = o.product_slug
       WHERE o.customer_id = ?
         AND o.payment_status = 'paid'
         AND ${voucherModeSql()}`,
    ).bind(customerId).all<{ reference_id: string }>(),
    db.prepare(
      `SELECT o.id, o.reference_id, o.product_name, o.package_label,
              o.provider_serial_number, o.updated_at
       FROM orders o
       WHERE o.customer_id = ?
         AND o.payment_status = 'paid'
         AND o.provider_code <> 'voucher-stock'
         AND o.provider_serial_number IS NOT NULL
         AND TRIM(o.provider_serial_number) <> ''
       ORDER BY o.updated_at DESC
       LIMIT 50`,
    ).bind(customerId).all<ProviderVoucherRow>(),
  ]);

  const eligibleReferences = new Set(eligibleResult.results.map((row) => row.reference_id));
  const providerCodes = providerResult.results
    .filter((row) => eligibleReferences.has(row.reference_id))
    .map((row) => ({
      id: stableNumericId(row.id),
      referenceId: row.reference_id,
      productName: row.product_name,
      packageLabel: row.package_label,
      code: row.provider_serial_number,
      deliveredAt: row.updated_at,
    }));

  return [
    ...stockCodes.filter((row) => eligibleReferences.has(row.referenceId)),
    ...providerCodes,
  ]
    .sort(
      (left, right) =>
        new Date(right.deliveredAt ?? 0).getTime() -
        new Date(left.deliveredAt ?? 0).getTime(),
    )
    .slice(0, 50);
}

export async function getWebsiteVoucherCodeByReference(referenceId: string) {
  const order = await getD1()
    .prepare(
      `SELECT o.id, o.reference_id, o.payment_status, o.provider_code,
              o.provider_serial_number
       FROM orders o
       JOIN products p ON p.slug = o.product_slug
       WHERE o.reference_id = ?
         AND ${voucherModeSql()}
       LIMIT 1`,
    )
    .bind(referenceId)
    .first<WebsiteVoucherOrderRow>();

  if (!order || order.payment_status !== "paid") return null;

  if (order.provider_code === "voucher-stock") {
    const voucher = await revealVoucherCode(order.id).catch(() => null);
    return voucher?.code ?? null;
  }

  const serial = order.provider_serial_number?.trim();
  return serial || null;
}
