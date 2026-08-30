import { requireCustomerSession } from "@/lib/server/customer-auth";
import { uploadStoreMedia } from "@/lib/server/media";
import { createWalletTopup, readWalletSettings } from "@/lib/server/wallet";

export async function POST(request: Request) {
  const customer = await requireCustomerSession(request);
  if (customer instanceof Response) return customer;
  try {
    const settings = await readWalletSettings();
    if (!settings.isEnabled || !settings.accountNumber) throw new Error("Top up saldo belum dibuka oleh Pemilik.");
    const form = await request.formData();
    const amount = Number(form.get("amount"));
    const senderName = String(form.get("senderName") ?? "").trim();
    const paymentMethod = String(form.get("paymentMethod") ?? settings.methodName).trim();
    const file = form.get("proof");
    if (!Number.isInteger(amount) || amount < settings.minTopup || amount > 100_000_000) throw new Error(`Minimum top up Rp${settings.minTopup.toLocaleString("id-ID")}.`);
    if (senderName.length < 2 || senderName.length > 100) throw new Error("Nama pengirim tidak valid.");
    if (!(file instanceof File)) throw new Error("Unggah bukti pembayaran terlebih dahulu.");
    const key = await uploadStoreMedia(file);
    const id = await createWalletTopup({ customerId: customer.id, amount, senderName, paymentMethod, proofUrl: key });
    return Response.json({ ok: true, id }, { status: 201 });
  } catch (error) {
    return Response.json({ error: error instanceof Error ? error.message : "Permintaan top up gagal." }, { status: 400 });
  }
}
