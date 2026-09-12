import { z } from "zod";
import { requireAdminSession } from "@/lib/server/admin";
import {
  readPaymentPageSettings,
  savePaymentPageSettings,
} from "@/lib/server/payment-page-settings";
import { isAllowedMediaUrl } from "@/lib/media-url";

const mediaUrl = z.union([
  z.literal(""),
  z.string().trim().max(500).refine(isAllowedMediaUrl, "URL gambar tidak valid."),
]);

const supportUrl = z.string().trim().max(500).refine((value) => {
  if (!value) return true;
  if (value.startsWith("/")) return true;
  try {
    const parsed = new URL(value);
    return parsed.protocol === "https:" || parsed.protocol === "http:";
  } catch {
    return false;
  }
}, "URL bantuan tidak valid.");

const schema = z.object({
  accentColor: z.string().trim().regex(/^#[0-9a-fA-F]{6}$/, "Warna harus format HEX, contoh #b9ff35."),
  headerImageUrl: mediaUrl,
  eyebrow: z.string().trim().min(1).max(60),
  pendingTitle: z.string().trim().min(2).max(100),
  paidTitle: z.string().trim().min(2).max(100),
  failedTitle: z.string().trim().min(2).max(100),
  subtitle: z.string().trim().max(240),
  invoiceNoticeTitle: z.string().trim().min(2).max(100),
  invoiceNoticeText: z.string().trim().max(240),
  pendingStatusText: z.string().trim().max(240),
  paidStatusText: z.string().trim().max(240),
  failedStatusText: z.string().trim().max(240),
  payButtonText: z.string().trim().min(1).max(40),
  checkStatusButtonText: z.string().trim().min(1).max(40),
  checkInvoiceButtonText: z.string().trim().min(1).max(40),
  supportText: z.string().trim().max(120),
  supportUrl,
  showStoreBrand: z.boolean(),
  showInvoiceNotice: z.boolean(),
  showOrderSummary: z.boolean(),
  showStatusBox: z.boolean(),
  showSupport: z.boolean(),
});

export async function GET(request: Request) {
  const access = await requireAdminSession(request, "admin");
  if (access instanceof Response) return access;
  return Response.json({ settings: await readPaymentPageSettings() });
}

export async function PUT(request: Request) {
  const access = await requireAdminSession(request, "admin");
  if (access instanceof Response) return access;
  try {
    const settings = schema.parse(await request.json());
    await savePaymentPageSettings(settings);
    return Response.json({ ok: true, settings });
  } catch (error) {
    const message = error instanceof z.ZodError
      ? error.issues[0]?.message || "Pengaturan halaman pembayaran tidak valid."
      : error instanceof Error
        ? error.message
        : "Pengaturan halaman pembayaran gagal disimpan.";
    return Response.json({ error: message }, { status: 400 });
  }
}
