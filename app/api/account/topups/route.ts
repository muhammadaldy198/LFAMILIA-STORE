import { z } from "zod";
import { requireCustomerSession } from "@/lib/server/customer-auth";
import { uploadStoreMedia } from "@/lib/server/media";
import { createMidtransSnapPayment } from "@/lib/server/midtrans";
import { isPaymentChannelAvailable } from "@/lib/server/payment-channels";
import { getRuntimeEnv } from "@/lib/server/runtime-env";
import { allowRequest } from "@/lib/server/security";
import {
  createMidtransWalletTopup,
  createWalletTopup,
  readWalletSettings,
  updateMidtransWalletTopup,
} from "@/lib/server/wallet";

type RuntimeEnv = { PUBLIC_BASE_URL?: string };

const automaticSchema = z.object({
  mode: z.literal("midtrans"),
  amount: z.number().int().min(1000).max(100_000_000),
  paymentMethod: z.enum(["va", "ewallet", "qris"]),
  paymentChannel: z.string().trim().min(2).max(30),
});

export async function POST(request: Request) {
  const customer = await requireCustomerSession(request);
  if (customer instanceof Response) return customer;
  const rate = await allowRequest(request, "wallet-topup", 8, 900);
  if (!rate.allowed)
    return Response.json(
      { error: "Terlalu banyak permintaan top up. Coba lagi beberapa menit." },
      { status: 429, headers: { "Retry-After": String(rate.retryAfter) } },
    );
  try {
    const settings = await readWalletSettings();
    if (request.headers.get("content-type")?.includes("application/json")) {
      const input = automaticSchema.parse(await request.json());
      if (!settings.midtransTopupEnabled)
        throw new Error(
          "Top up otomatis Midtrans belum diaktifkan oleh Pemilik.",
        );
      if (input.amount < settings.minTopup)
        throw new Error(
          `Minimum top up Rp${settings.minTopup.toLocaleString("id-ID")}.`,
        );
      if (
        !(await isPaymentChannelAvailable(
          input.paymentMethod,
          input.paymentChannel,
        ))
      )
        throw new Error("Metode Midtrans tidak tersedia.");
      const referenceId = `WLT-${crypto.randomUUID().replace(/-/g, "").slice(0, 20).toUpperCase()}`;
      await createMidtransWalletTopup({
        customerId: customer.id,
        amount: input.amount,
        name: customer.name,
        paymentMethod: input.paymentMethod,
        paymentChannel: input.paymentChannel,
        referenceId,
      });
      const configured = getRuntimeEnv<RuntimeEnv>().PUBLIC_BASE_URL?.trim();
      try {
        const payment = await createMidtransSnapPayment({
          buyerName: customer.name,
          buyerPhone: customer.phone,
          buyerEmail: customer.email,
          amount: input.amount,
          referenceId,
          paymentMethod: input.paymentMethod,
          paymentChannel: input.paymentChannel,
          productName: "Top up Saldo LFAMILIA",
          finishUrl: `${new URL(configured || request.url).origin}/account`,
        });
        await updateMidtransWalletTopup({
          referenceId,
          transactionId: payment.transactionId,
          paymentNo: null,
          paymentName: "Midtrans Snap",
          paymentUrl: payment.paymentUrl,
          expiredAt: null,
          fee: 0,
          total: input.amount,
        });
        return Response.json(
          {
            ok: true,
            mode: "midtrans",
            referenceId,
            paymentUrl: payment.paymentUrl,
            total: input.amount,
            fee: 0,
            expiredAt: null,
          },
          { status: 201 },
        );
      } catch (error) {
        throw error;
      }
    }
    if (!settings.isEnabled || !settings.accountNumber)
      throw new Error("Top up manual bank belum dibuka oleh Pemilik.");
    const form = await request.formData();
    const amount = Number(form.get("amount"));
    const senderName = String(form.get("senderName") ?? "").trim();
    const paymentMethod = String(
      form.get("paymentMethod") ?? settings.methodName,
    ).trim();
    if (paymentMethod === "QRIS Manual" && !settings.manualQrisEnabled)
      throw new Error("QRIS manual belum diaktifkan oleh Pemilik.");
    const file = form.get("proof");
    if (
      !Number.isInteger(amount) ||
      amount < settings.minTopup ||
      amount > 100_000_000
    )
      throw new Error(
        `Minimum top up Rp${settings.minTopup.toLocaleString("id-ID")}.`,
      );
    if (senderName.length < 2 || senderName.length > 100)
      throw new Error("Nama pengirim tidak valid.");
    if (!(file instanceof File))
      throw new Error("Unggah bukti pembayaran terlebih dahulu.");
    const key = await uploadStoreMedia(file);
    const id = await createWalletTopup({
      customerId: customer.id,
      amount,
      senderName,
      paymentMethod,
      proofUrl: key,
    });
    return Response.json({ ok: true, id }, { status: 201 });
  } catch (error) {
    return Response.json(
      {
        error:
          error instanceof Error ? error.message : "Permintaan top up gagal.",
      },
      { status: 400 },
    );
  }
}
