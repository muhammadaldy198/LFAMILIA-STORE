import { z } from "zod";
import { findPaymentChannel } from "@/lib/payment-methods";
import { createIpaymuDirectPayment } from "@/lib/server/ipaymu";
import {
  createOrderIdentity,
  insertPendingOrder,
  markPaymentCreationFailed,
  recordOrderEvent,
  resolvePurchasableItem,
  updateIpaymuPayment,
} from "@/lib/server/orders";
import { getRuntimeEnv } from "@/lib/server/runtime-env";
import { hasAvailableVoucherStock } from "@/lib/server/vouchers";
import { quotePromotion } from "@/lib/server/promotions";

export const dynamic = "force-dynamic";

type RuntimeEnv = { PUBLIC_BASE_URL?: string };

const schema = z.object({
  productSlug: z.string().trim().min(2).max(80),
  packageSku: z.string().trim().min(2).max(100),
  destination: z.string().trim().min(2).max(150),
  server: z.string().trim().max(40).optional(),
  nickname: z.string().trim().max(100).optional(),
  buyerName: z.string().trim().min(2).max(100),
  buyerEmail: z.string().trim().email().max(150),
  buyerPhone: z.string().trim().regex(/^\+?[0-9]{8,16}$/),
  customerNotes: z.string().trim().max(500).optional(),
  paymentMethod: z.enum(["va", "ewallet", "qris"]),
  paymentChannel: z.string().trim().min(2).max(30),
  voucherCode: z.string().trim().max(40).optional(),
});

function publicBaseUrl(request: Request) {
  const configured = getRuntimeEnv<RuntimeEnv>().PUBLIC_BASE_URL?.trim();
  return new URL(configured || request.url).origin;
}

export async function POST(request: Request) {
  let referenceId: string | null = null;
  try {
    const input = schema.parse(await request.json());
    if (!findPaymentChannel(input.paymentMethod, input.paymentChannel)) {
      return Response.json({ error: "Metode pembayaran iPaymu tidak valid." }, { status: 400 });
    }
    const item = await resolvePurchasableItem(input.productSlug, input.packageSku);
    if (!item) return Response.json({ error: "Produk atau nominal tidak tersedia." }, { status: 404 });
    if (item.needsServer && !input.server) return Response.json({ error: "Server / Zone ID wajib diisi." }, { status: 400 });
    if (item.providerCode === "voucher-stock" && item.providerSku && !await hasAvailableVoucherStock(item.providerSku)) {
      return Response.json({ error: "Stok kode untuk paket ini sedang habis." }, { status: 409 });
    }
    const promotion = await quotePromotion(item.productSlug, item.packageSku, item.price, input.voucherCode);

    const identity = createOrderIdentity();
    referenceId = identity.referenceId;
    await insertPendingOrder({
      ...identity,
      item,
      destination: input.destination,
      server: input.server || null,
      nickname: input.nickname || null,
      buyerName: input.buyerName,
      buyerEmail: input.buyerEmail,
      buyerPhone: input.buyerPhone,
      customerNotes: input.customerNotes || null,
      paymentMethod: input.paymentMethod,
      paymentChannel: input.paymentChannel,
      promotion,
    });

    const baseUrl = publicBaseUrl(request);
    const payment = await createIpaymuDirectPayment({
      name: input.buyerName,
      phone: input.buyerPhone,
      email: input.buyerEmail,
      amount: promotion.finalPrice,
      notifyUrl: `${baseUrl}/api/payments/ipaymu/callback`,
      referenceId: identity.referenceId,
      paymentMethod: input.paymentMethod,
      paymentChannel: input.paymentChannel,
      productName: `${item.productName} - ${item.packageLabel}`,
      productPrice: promotion.finalPrice,
    });
    await updateIpaymuPayment({
      referenceId: identity.referenceId,
      transactionId: payment.transactionId,
      paymentNo: payment.paymentNo,
      paymentName: payment.paymentName,
      paymentUrl: payment.paymentUrl,
      expiredAt: payment.expiredAt,
      fee: payment.fee,
      total: payment.total,
    });
    await recordOrderEvent({
      orderId: identity.id,
      source: "ipaymu",
      eventId: `create-${identity.referenceId}`,
      status: "pending",
      payload: payment.raw,
    });
    return Response.json({
      orderId: identity.id,
      referenceId: identity.referenceId,
      paymentNo: payment.paymentNo,
      paymentName: payment.paymentName,
      paymentUrl: payment.paymentUrl,
      fee: payment.fee,
      total: payment.total,
      expiredAt: payment.expiredAt,
      fulfillmentType: item.fulfillmentType,
      providerCode: item.providerCode,
      basePrice: promotion.basePrice,
      sellingPrice: promotion.sellingPrice,
      discountAmount: promotion.discountAmount,
      voucherCode: promotion.voucherCode,
      flashSaleId: promotion.flashSaleId,
    }, { status: 201 });
  } catch (error) {
    const message = error instanceof z.ZodError
      ? error.issues[0]?.message || "Data checkout tidak valid."
      : error instanceof Error ? error.message : "Pembayaran gagal dibuat.";
    if (referenceId) await markPaymentCreationFailed(referenceId, message).catch(() => undefined);
    return Response.json({ error: message }, { status: error instanceof z.ZodError ? 400 : 503 });
  }
}
