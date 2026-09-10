import { z } from "zod";
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
  createDokuWalletTopup,
  markAutomaticWalletTopupCreationFailed,
  readWalletSettings,
  updateDokuWalletTopup,
} from "@/lib/server/wallet";

const automaticSchema = z.object({
  amount: z.number().int().min(1000).max(100_000_000),
  paymentMethod: z.enum(["va", "ewallet", "qris"]),
  paymentChannel: z.string().trim().min(2).max(30),
});

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

    referenceId = `WLT-${crypto.randomUUID().replace(/-/g, "").slice(0, 20).toUpperCase()}`;
    await createDokuWalletTopup({
      customerId: customer.id,
      amount: input.amount,
      name: customer.name,
      paymentMethod: input.paymentMethod,
      paymentChannel,
      referenceId,
    });

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
