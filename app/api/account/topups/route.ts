import { z } from "zod";
import { getD1 } from "@/db";
import { publicPaymentLabel } from "@/lib/public-payment";
import { requireCustomerSession } from "@/lib/server/customer-auth";
import {
  createDokuDirectPayment,
  getDokuReadiness,
  isDokuChannelSupported,
} from "@/lib/server/doku";
import { isPaymentChannelAvailable } from "@/lib/server/payment-channels";
import { getPublicBaseUrl } from "@/lib/server/runtime-env";
import { allowRequest, rejectCrossOriginMutation } from "@/lib/server/security";
import {
  markAutomaticWalletTopupCreationFailed,
  readWalletSettings,
  updateDokuWalletTopup,
} from "@/lib/server/wallet";

const automaticSchema = z.object({
  amount: z.number().int().min(1000).max(100_000_000),
  paymentMethod: z.enum(["va", "ewallet", "qris"]),
  paymentChannel: z.string().trim().min(2).max(30),
  idempotencyKey: z.string().uuid().optional(),
});

type ExistingTopup = {
  id: string;
  amount: number;
  payment_method: string;
  reference_id: string;
  status: string;
  doku_payment_no: string | null;
  doku_qr_content: string | null;
  doku_payment_name: string | null;
  doku_payment_url: string | null;
  doku_expired_at: string | null;
};

function splitPaymentMethod(value: string) {
  const [paymentMethod = "", paymentChannel = ""] = value.split(":", 2);
  return { paymentMethod, paymentChannel };
}

function existingResponse(topup: ExistingTopup) {
  if (topup.status === "rejected") {
    return Response.json(
      { error: "Permintaan top up sebelumnya sudah gagal. Buat permintaan baru dengan idempotency key baru." },
      { status: 409 },
    );
  }
  const hasInstructions = Boolean(
    topup.doku_payment_no || topup.doku_qr_content || topup.doku_payment_url,
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
    paymentNo: topup.doku_payment_no,
    qrContent: topup.doku_qr_content,
    paymentName: topup.doku_payment_name || publicPaymentLabel(method.paymentMethod, method.paymentChannel),
    paymentUrl: topup.doku_payment_url,
    total: topup.amount,
    fee: 0,
    expiredAt: topup.doku_expired_at,
    status: topup.status,
    reused: true,
  });
}

async function findTopupByKey(customerId: string, idempotencyKey: string) {
  return getD1()
    .prepare(
      `SELECT id, amount, payment_method, reference_id, status,
        doku_payment_no, doku_qr_content, doku_payment_name, doku_payment_url, doku_expired_at
       FROM wallet_topups
       WHERE customer_id = ? AND external_checkout_key = ? AND source = 'doku'
       LIMIT 1`,
    )
    .bind(customerId, idempotencyKey)
    .first<ExistingTopup>();
}

async function findMatchingPendingTopup(customerId: string, amount: number, paymentMethod: string) {
  return getD1()
    .prepare(
      `SELECT id, amount, payment_method, reference_id, status,
        doku_payment_no, doku_qr_content, doku_payment_name, doku_payment_url, doku_expired_at
       FROM wallet_topups
       WHERE customer_id = ? AND amount = ? AND payment_method = ?
         AND source = 'doku' AND status = 'pending'
       ORDER BY created_at DESC LIMIT 1`,
    )
    .bind(customerId, amount, paymentMethod)
    .first<ExistingTopup>();
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
    const readiness = getDokuReadiness();
    if (!settings.dokuTopupEnabled || !readiness.ready) {
      throw new Error(
        readiness.ready
          ? "Top up saldo otomatis sedang dinonaktifkan."
          : "Pembayaran otomatis belum siap.",
      );
    }

    const input = automaticSchema.parse(await request.json());
    if (input.amount < settings.minTopup) {
      throw new Error(`Minimum top up Rp${settings.minTopup.toLocaleString("id-ID")}.`);
    }

    const paymentChannel =
      input.paymentMethod === "qris" && input.paymentChannel === "qris"
        ? "mpm"
        : input.paymentChannel;
    if (
      !isDokuChannelSupported(input.paymentMethod, paymentChannel) ||
      !(await isPaymentChannelAvailable(input.paymentMethod, paymentChannel))
    ) {
      throw new Error("Metode pembayaran ini belum didukung atau sedang dinonaktifkan.");
    }

    const paymentMethodKey = `${input.paymentMethod}:${paymentChannel}`;
    requestedAmount = input.amount;
    requestedPaymentMethodKey = paymentMethodKey;
    const headerKey = request.headers.get("idempotency-key")?.trim() || "";
    idempotencyKey = input.idempotencyKey || (/^[0-9a-f-]{36}$/i.test(headerKey) ? headerKey : "");
    if (idempotencyKey) {
      const existing = await findTopupByKey(customer.id, idempotencyKey);
      if (existing) {
        if (existing.amount !== input.amount || existing.payment_method !== paymentMethodKey) {
          return Response.json({ error: "Idempotency key sudah dipakai untuk permintaan top up berbeda." }, { status: 409 });
        }
        return existingResponse(existing);
      }
    }

    const pending = await findMatchingPendingTopup(customer.id, input.amount, paymentMethodKey);
    if (pending) return existingResponse(pending);

    referenceId = `WLT-${crypto.randomUUID().replace(/-/g, "").slice(0, 20).toUpperCase()}`;
    const topupId = crypto.randomUUID();
    const inserted = await getD1()
      .prepare(
        `INSERT INTO wallet_topups (
          id, customer_id, amount, sender_name, payment_method, proof_url,
          source, reference_id, external_checkout_key, doku_environment
        )
        SELECT ?, ?, ?, ?, ?, '', 'doku', ?, ?, ?
        WHERE NOT EXISTS (
          SELECT 1 FROM wallet_topups
          WHERE customer_id = ? AND amount = ? AND payment_method = ?
            AND source = 'doku' AND status = 'pending'
        )`,
      )
      .bind(
        topupId,
        customer.id,
        input.amount,
        customer.name,
        paymentMethodKey,
        referenceId,
        idempotencyKey || null,
        readiness.environment,
        customer.id,
        input.amount,
        paymentMethodKey,
      )
      .run();

    if (Number(inserted.meta.changes ?? 0) === 0) {
      const winner = idempotencyKey
        ? await findTopupByKey(customer.id, idempotencyKey)
        : await findMatchingPendingTopup(customer.id, input.amount, paymentMethodKey);
      if (winner) return existingResponse(winner);
      return Response.json(
        { error: "Permintaan top up yang sama sedang dibuat. Coba lagi beberapa detik." },
        { status: 409 },
      );
    }

    const baseUrl = getPublicBaseUrl();
    const payment = await createDokuDirectPayment({
      buyerName: customer.name,
      buyerPhone: customer.phone,
      buyerEmail: customer.email,
      amount: input.amount,
      referenceId,
      paymentMethod: input.paymentMethod,
      paymentChannel,
      productName: "Top up Saldo LFAMILIA",
      finishUrl: `${baseUrl}/account`,
    });

    await updateDokuWalletTopup({
      referenceId,
      requestId: payment.requestId,
      referenceNo: payment.referenceNo,
      paymentNo: payment.paymentNo,
      qrContent: payment.qrContent,
      paymentName: payment.paymentName,
      paymentUrl: payment.paymentUrl,
      expiredAt: payment.expiredAt,
      total: input.amount,
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
        total: input.amount,
        fee: 0,
        expiredAt: payment.expiredAt,
      },
      { status: 201 },
    );
  } catch (error) {
    if (idempotencyKey && error instanceof Error && /UNIQUE constraint failed/i.test(error.message)) {
      const winner = await findTopupByKey(customer.id, idempotencyKey).catch(() => null);
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
      await markAutomaticWalletTopupCreationFailed(referenceId, message).catch(
        () => undefined,
      );
    }
    return Response.json(
      { error: message },
      { status: error instanceof z.ZodError ? 400 : 503 },
    );
  }
}
