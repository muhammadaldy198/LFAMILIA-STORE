import { z } from "zod";
import { requireCustomerSession } from "@/lib/server/customer-auth";
import { uploadStoreMedia } from "@/lib/server/media";
import {
  createMidtransPayment,
  getMidtransMode,
  isMidtransChannelSupported,
} from "@/lib/server/midtrans";
import {
  createIpaymuDirectPayment,
  isIpaymuChannelSupported,
} from "@/lib/server/ipaymu";
import { isPaymentChannelAvailable } from "@/lib/server/payment-channels";
import { getPublicBaseUrl } from "@/lib/server/runtime-env";
import { allowRequest } from "@/lib/server/security";
import {
  createIpaymuWalletTopup,
  createMidtransWalletTopup,
  createWalletTopup,
  readWalletSettings,
  updateIpaymuWalletTopup,
  updateMidtransWalletTopup,
} from "@/lib/server/wallet";

const automaticSchema = z.object({
  mode: z.enum(["midtrans", "ipaymu"]),
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
      const gatewayEnabled =
        input.mode === "midtrans"
          ? settings.midtransTopupEnabled
          : settings.ipaymuTopupEnabled;
      if (!gatewayEnabled)
        throw new Error(
          `Top up otomatis ${input.mode === "midtrans" ? "Midtrans" : "iPaymu"} belum diaktifkan oleh Pemilik.`,
        );
      if (input.amount < settings.minTopup)
        throw new Error(
          `Minimum top up Rp${settings.minTopup.toLocaleString("id-ID")}.`,
        );
      const midtransMode =
        input.mode === "midtrans" ? getMidtransMode() : null;
      if (
        !(await isPaymentChannelAvailable(
          input.paymentMethod,
          input.paymentChannel,
        )) ||
        (input.mode === "ipaymu" &&
          !isIpaymuChannelSupported(
            input.paymentMethod,
            input.paymentChannel,
          )) ||
        (input.mode === "midtrans" &&
          midtransMode &&
          !isMidtransChannelSupported(
            input.paymentMethod,
            input.paymentChannel,
            midtransMode,
          ))
      )
        throw new Error("Metode pembayaran otomatis tidak tersedia.");

      const referenceId = `WLT-${crypto.randomUUID().replace(/-/g, "").slice(0, 20).toUpperCase()}`;

      if (input.mode === "ipaymu") {
        await createIpaymuWalletTopup({
          customerId: customer.id,
          amount: input.amount,
          name: customer.name,
          paymentMethod: input.paymentMethod,
          paymentChannel: input.paymentChannel,
          referenceId,
        });
        const payment = await createIpaymuDirectPayment({
          name: customer.name,
          phone: customer.phone,
          email: customer.email,
          amount: input.amount,
          notifyUrl: `${getPublicBaseUrl()}/api/payments/ipaymu/callback`,
          referenceId,
          paymentMethod: input.paymentMethod,
          paymentChannel: input.paymentChannel,
          productName: "Top up Saldo LFAMILIA",
          productPrice: input.amount,
        });
        await updateIpaymuWalletTopup({
          referenceId,
          transactionId: payment.transactionId,
          paymentNo: payment.paymentNo,
          paymentName: payment.paymentName,
          paymentUrl: payment.paymentUrl,
          expiredAt: payment.expiredAt,
          fee: payment.fee,
          total: payment.total,
        });
        return Response.json(
          {
            ok: true,
            mode: "ipaymu",
            referenceId,
            paymentUrl: payment.paymentUrl,
            total: payment.total,
            fee: payment.fee,
            expiredAt: payment.expiredAt,
          },
          { status: 201 },
        );
      }

      await createMidtransWalletTopup({
        customerId: customer.id,
        amount: input.amount,
        name: customer.name,
        paymentMethod: input.paymentMethod,
        paymentChannel: input.paymentChannel,
        referenceId,
      });
      const payment = await createMidtransPayment({
        buyerName: customer.name,
        buyerPhone: customer.phone,
        buyerEmail: customer.email,
        amount: input.amount,
        referenceId,
        paymentMethod: input.paymentMethod,
        paymentChannel: input.paymentChannel,
        productName: "Top up Saldo LFAMILIA",
        finishUrl: `${getPublicBaseUrl()}/account`,
        deviceId: request.headers.get("user-agent") || undefined,
      });
      await updateMidtransWalletTopup({
        referenceId,
        mode: payment.mode,
        transactionId: payment.transactionId,
        paymentNo: payment.paymentNo,
        paymentName: payment.paymentName,
        paymentUrl: payment.paymentUrl,
        expiredAt: payment.expiredAt,
        fee: 0,
        total: input.amount,
      });
      return Response.json(
        {
          ok: true,
          mode: "midtrans",
          referenceId,
          paymentNo: payment.paymentNo,
          paymentName: payment.paymentName,
          paymentUrl: payment.paymentUrl,
          total: input.amount,
          fee: 0,
          expiredAt: payment.expiredAt,
        },
        { status: 201 },
      );
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
