import { z } from "zod";
import { publicPaymentLabel } from "@/lib/public-payment";
import { requireCustomerSession } from "@/lib/server/customer-auth";
import {
  calculateCustomerPaymentFee,
  getPaymentChannel,
  isGatewayChannelSupported,
  isPaymentGatewayActive,
} from "@/lib/server/payment-channels";
import { getActivePaymentModes } from "@/lib/server/payment-mode-config";
import { createConfiguredPayment, getConfiguredGatewayReadiness } from "@/lib/server/payment-router";
import { getPublicBaseUrl } from "@/lib/server/runtime-env";
import { allowRequest, rejectCrossOriginMutation } from "@/lib/server/security";
import { readWalletSettings } from "@/lib/server/wallet";
import {
  findExternalTopupByKey,
  findMatchingExternalTopup,
  insertExternalWalletTopup,
  markExternalWalletTopupCreationFailed,
  updateExternalWalletTopup,
  type ExternalWalletTopup,
} from "@/lib/server/wallet-external";

const automaticSchema = z.object({
  amount: z.number().int().min(1000).max(100_000_000),
  paymentMethod: z.enum(["va", "ewallet", "qris"]),
  paymentChannel: z.string().trim().min(2).max(30),
  idempotencyKey: z.string().uuid().optional(),
});

function splitPaymentMethod(value: string) {
  const [paymentMethod = "", paymentChannel = ""] = value.split(":", 2);
  return { paymentMethod, paymentChannel };
}

function existingResponse(topup: ExternalWalletTopup) {
  if (topup.status === "rejected") {
    return Response.json(
      { error: "Permintaan top up sebelumnya sudah gagal. Buat permintaan baru dengan idempotency key baru." },
      { status: 409 },
    );
  }
  const hasInstructions = Boolean(
    topup.gateway_payment_no || topup.gateway_qr_content || topup.gateway_payment_url,
  );
  if (topup.status === "pending" && !hasInstructions) {
    return Response.json(
      { error: "Permintaan top up sedang dibuat. Coba lagi beberapa detik." },
      { status: 409 },
    );
  }
  const method = splitPaymentMethod(topup.payment_method);
  return Response.json({
    ok: true,
    referenceId: topup.reference_id,
    paymentMethod: method.paymentMethod,
    paymentChannel: method.paymentChannel,
    paymentNo: topup.gateway_payment_no,
    qrContent: topup.gateway_qr_content,
    paymentName: topup.gateway_payment_name || publicPaymentLabel(method.paymentMethod, method.paymentChannel),
    paymentUrl: topup.gateway_payment_url,
    total: topup.payment_total || topup.amount,
    fee: topup.payment_fee || 0,
    expiredAt: topup.gateway_expired_at,
    status: topup.status,
    reused: true,
  });
}

export async function POST(request: Request) {
  const originBlock = rejectCrossOriginMutation(request);
  if (originBlock) return originBlock;
  const customer = await requireCustomerSession(request);
  if (customer instanceof Response) return customer;
  const rate = await allowRequest(request, "wallet-topup", 8, 900);
  if (!rate.allowed) {
    return Response.json(
      { error: "Terlalu banyak permintaan top up. Coba lagi beberapa menit." },
      { status: 429, headers: { "Retry-After": String(rate.retryAfter) } },
    );
  }

  let referenceId: string | null = null;
  let idempotencyKey = "";
  let requestedAmount: number | null = null;
  let requestedPaymentMethodKey = "";
  try {
    if (!request.headers.get("content-type")?.includes("application/json")) {
      throw new Error("Top up saldo hanya tersedia melalui pembayaran otomatis.");
    }

    const settings = await readWalletSettings();
    if (!settings.automaticTopupEnabled) {
      throw new Error("Top up saldo otomatis sedang dinonaktifkan.");
    }

    const input = automaticSchema.parse(await request.json());
    if (input.amount < settings.minTopup) {
      throw new Error(`Minimum top up Rp${settings.minTopup.toLocaleString("id-ID")}.`);
    }

    const paymentChannel =
      input.paymentMethod === "qris" && input.paymentChannel === "qris"
        ? "mpm"
        : input.paymentChannel;
    const managedChannel = await getPaymentChannel(input.paymentMethod, paymentChannel, false);
    const { walletTopupGateway } = await getActivePaymentModes();
    const topupGatewayConfig = managedChannel
      ? managedChannel.gateway === walletTopupGateway
        ? managedChannel.gatewayConfig
        : { customerFeeBps: managedChannel.gatewayConfig.customerFeeBps ?? "0" }
      : {};
    if (
      !managedChannel ||
      !isGatewayChannelSupported(walletTopupGateway, input.paymentMethod, paymentChannel, topupGatewayConfig) ||
      !(await isPaymentGatewayActive(walletTopupGateway))
    ) {
      throw new Error("Metode pembayaran ini belum didukung atau gateway top up sedang dinonaktifkan.");
    }

    const readiness = await getConfiguredGatewayReadiness({
      gateway: walletTopupGateway,
      paymentMethod: input.paymentMethod,
      paymentChannel,
      gatewayConfig: topupGatewayConfig,
    });
    if (!readiness.ready) throw new Error("Gateway top up saldo yang dipilih Admin belum siap.");

    const paymentMethodKey = `${input.paymentMethod}:${paymentChannel}`;
    const paymentFee = calculateCustomerPaymentFee(input.amount, topupGatewayConfig);
    const paymentTotal = input.amount + paymentFee;
    requestedAmount = input.amount;
    requestedPaymentMethodKey = paymentMethodKey;
    const headerKey = request.headers.get("idempotency-key")?.trim() || "";
    idempotencyKey = input.idempotencyKey || (/^[0-9a-f-]{36}$/i.test(headerKey) ? headerKey : "");
    if (idempotencyKey) {
      const existing = await findExternalTopupByKey(customer.id, idempotencyKey);
      if (existing) {
        if (existing.amount !== input.amount || existing.payment_method !== paymentMethodKey) {
          return Response.json({ error: "Idempotency key sudah dipakai untuk permintaan top up berbeda." }, { status: 409 });
        }
        return existingResponse(existing);
      }
    }

    const pending = await findMatchingExternalTopup(customer.id, input.amount, paymentMethodKey);
    if (pending) return existingResponse(pending);

    referenceId = `WLT-${crypto.randomUUID().replace(/-/g, "").slice(0, 20).toUpperCase()}`;
    const topupId = crypto.randomUUID();
    const inserted = await insertExternalWalletTopup({
      id: topupId,
      customerId: customer.id,
      amount: input.amount,
      paymentFee,
      paymentTotal,
      customerName: customer.name,
      paymentMethodKey,
      referenceId,
      idempotencyKey: idempotencyKey || null,
      gateway: walletTopupGateway,
      mode: readiness.mode,
      environment: readiness.environment,
    });

    if (Number(inserted.meta.changes ?? 0) === 0) {
      const winner = idempotencyKey
        ? await findExternalTopupByKey(customer.id, idempotencyKey)
        : await findMatchingExternalTopup(customer.id, input.amount, paymentMethodKey);
      if (winner) return existingResponse(winner);
      return Response.json(
        { error: "Permintaan top up yang sama sedang dibuat. Coba lagi beberapa detik." },
        { status: 409 },
      );
    }

    const baseUrl = getPublicBaseUrl();
    const payment = await createConfiguredPayment({
      gateway: walletTopupGateway,
      referenceId,
      amount: paymentTotal,
      paymentMethod: input.paymentMethod,
      paymentChannel,
      gatewayConfig: topupGatewayConfig,
      buyerName: customer.name,
      buyerPhone: customer.phone,
      buyerEmail: customer.email,
      productName: "Top up Saldo LFAMILIA",
      packageLabel: "Saldo akun",
      finishUrl: `${baseUrl}/account`,
      deviceId: idempotencyKey || referenceId,
    });

    await updateExternalWalletTopup({
      referenceId,
      gateway: payment.gateway,
      mode: payment.mode,
      environment: payment.environment,
      requestId: payment.requestId,
      referenceNo: payment.referenceNo,
      paymentNo: payment.paymentNo,
      qrContent: payment.qrContent,
      paymentName: payment.paymentName,
      paymentUrl: payment.paymentUrl,
      expiredAt: payment.expiredAt,
      total: paymentTotal,
    });

    return Response.json(
      {
        ok: true,
        referenceId,
        paymentMethod: input.paymentMethod,
        paymentChannel,
        paymentNo: payment.paymentNo,
        qrContent: payment.qrContent,
        paymentName: publicPaymentLabel(input.paymentMethod, paymentChannel),
        paymentUrl: payment.paymentUrl,
        total: paymentTotal,
        fee: paymentFee,
        expiredAt: payment.expiredAt,
      },
      { status: 201 },
    );
  } catch (error) {
    if (idempotencyKey && error instanceof Error && /UNIQUE constraint failed/i.test(error.message)) {
      const winner = await findExternalTopupByKey(customer.id, idempotencyKey).catch(() => null);
      if (winner) {
        if (
          winner.amount !== requestedAmount ||
          winner.payment_method !== requestedPaymentMethodKey
        ) {
          return Response.json(
            { error: "Idempotency key sudah dipakai untuk permintaan top up berbeda." },
            { status: 409 },
          );
        }
        return existingResponse(winner);
      }
    }

    const message =
      error instanceof z.ZodError
        ? error.issues[0]?.message || "Data top up tidak valid."
        : error instanceof Error
          ? error.message
          : "Permintaan top up gagal.";

    if (referenceId) {
      await markExternalWalletTopupCreationFailed(referenceId, message).catch(() => undefined);
    }
    return Response.json(
      { error: message },
      { status: error instanceof z.ZodError ? 400 : 503 },
    );
  }
}
