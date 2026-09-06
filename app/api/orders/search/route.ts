import { z } from "zod";
import { getD1 } from "@/db";
import { allowRequest, rejectCrossOriginMutation } from "@/lib/server/security";

export const dynamic = "force-dynamic";

const schema = z.object({
  phone: z.string().trim().min(8).max(20),
});

function normalizePhoneVariants(value: string) {
  const digits = value.replace(/\D/g, "");
  if (digits.length < 8 || digits.length > 16) return [];

  let local = digits;
  if (digits.startsWith("62")) local = digits.slice(2);
  else if (digits.startsWith("0")) local = digits.slice(1);
  if (!local.startsWith("8")) return [];

  return Array.from(new Set([
    local,
    `0${local}`,
    `62${local}`,
    `+62${local}`,
  ]));
}

function maskInvoice(value: string) {
  const clean = value.trim().toUpperCase();
  if (clean.length <= 10) return `${clean.slice(0, 3)}••••${clean.slice(-3)}`;
  const prefix = clean.slice(0, 6);
  const suffix = clean.slice(-4);
  return `${prefix}${"•".repeat(Math.min(Math.max(clean.length - 10, 4), 10))}${suffix}`;
}

function publicReferenceId(value: string) {
  if (!value.includes("-")) return value;
  const token = value.split("-").at(-1) ?? value;
  return `LF${token}`;
}

function publicStatus(paymentStatus: string, fulfillmentStatus: string) {
  if (["failed", "expired"].includes(paymentStatus)) return paymentStatus;
  if (fulfillmentStatus === "success") return "success";
  if (["failed", "error", "needs_review"].includes(fulfillmentStatus)) return fulfillmentStatus;
  if (paymentStatus === "paid") return fulfillmentStatus || "processing";
  return paymentStatus || "pending";
}

type OrderSummaryRow = {
  reference_id: string;
  product_name: string;
  package_label: string;
  total: number;
  payment_status: string;
  fulfillment_status: string;
  created_at: string;
};

function mapSummary(row: OrderSummaryRow, exposeReference: boolean) {
  const referenceId = publicReferenceId(row.reference_id);
  return {
    referenceId: exposeReference ? referenceId : null,
    maskedReferenceId: maskInvoice(referenceId),
    productName: row.product_name,
    packageLabel: row.package_label,
    total: row.total,
    status: publicStatus(row.payment_status, row.fulfillment_status),
    createdAt: row.created_at,
  };
}

export async function GET() {
  try {
    const result = await getD1()
      .prepare(
        `SELECT reference_id, product_name, package_label, total,
         payment_status, fulfillment_status, created_at
         FROM orders
         ORDER BY created_at DESC
         LIMIT 30`,
      )
      .all<OrderSummaryRow>();

    return Response.json(
      { transactions: result.results.map((row) => mapSummary(row, false)) },
      { headers: { "Cache-Control": "no-store" } },
    );
  } catch {
    return Response.json(
      { error: "Riwayat transaksi belum dapat dimuat." },
      { status: 503, headers: { "Cache-Control": "no-store" } },
    );
  }
}

export async function POST(request: Request) {
  const originBlock = rejectCrossOriginMutation(request);
  if (originBlock) return originBlock;
  const rate = await allowRequest(request, "order-phone-search", 20, 600);
  if (!rate.allowed) return Response.json(
    { error: "Terlalu banyak pencarian transaksi. Coba lagi beberapa menit." },
    { status: 429, headers: { "Retry-After": String(rate.retryAfter) } },
  );
  try {
    const { phone } = schema.parse(await request.json());
    const variants = normalizePhoneVariants(phone);
    if (!variants.length) {
      return Response.json({ error: "Format nomor WhatsApp tidak valid." }, { status: 400 });
    }

    const placeholders = variants.map(() => "?").join(", ");
    const result = await getD1()
      .prepare(
        `SELECT reference_id, product_name, package_label, total,
         payment_status, fulfillment_status, created_at
         FROM orders
         WHERE buyer_phone IN (${placeholders})
         ORDER BY created_at DESC
         LIMIT 20`,
      )
      .bind(...variants)
      .all<OrderSummaryRow>();

    return Response.json(
      { orders: result.results.map((row) => mapSummary(row, false)) },
      { headers: { "Cache-Control": "no-store" } },
    );
  } catch (error) {
    if (error instanceof z.ZodError) {
      return Response.json({ error: "Format nomor WhatsApp tidak valid." }, { status: 400 });
    }
    return Response.json(
      { error: "Pesanan untuk nomor WhatsApp tersebut belum dapat dimuat." },
      { status: 503 },
    );
  }
}
