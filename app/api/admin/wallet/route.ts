import { z } from "zod";
import { requireAdminSession } from "@/lib/server/admin";
import { notifyWalletTopupSuccessById } from "@/lib/server/transaction-notifications";
import {
  listWalletTopups,
  readWalletSettings,
  reviewWalletTopup,
  saveWalletSettings,
} from "@/lib/server/wallet";

const settingsSchema = z.object({
  isEnabled: z.boolean(),
  methodName: z.string().trim().min(2).max(80),
  accountName: z.string().trim().max(100),
  accountNumber: z.string().trim().max(100),
  instructions: z.string().trim().max(600),
  minTopup: z.number().int().min(1000).max(100_000_000),
  manualQrisEnabled: z.boolean(),
  manualQrisName: z.string().trim().min(2).max(80),
  manualQrisImageUrl: z.string().trim().max(500),
  midtransTopupEnabled: z.boolean(),
  midtransCheckoutEnabled: z.boolean(),
});

export async function GET(request: Request) {
  const access = await requireAdminSession(request, "owner");
  if (access instanceof Response) return access;
  return Response.json({
    settings: await readWalletSettings(),
    topups: await listWalletTopups(),
  });
}

export async function PUT(request: Request) {
  const access = await requireAdminSession(request, "owner");
  if (access instanceof Response) return access;
  try {
    await saveWalletSettings(settingsSchema.parse(await request.json()));
    return Response.json({ ok: true });
  } catch (error) {
    const message =
      error instanceof z.ZodError
        ? error.issues[0]?.message
        : error instanceof Error
          ? error.message
          : "Pengaturan saldo gagal disimpan.";
    return Response.json({ error: message }, { status: 400 });
  }
}

const reviewSchema = z.object({
  id: z.string().uuid(),
  decision: z.enum(["approved", "rejected"]),
  notes: z.string().trim().max(300).optional(),
});

export async function PATCH(request: Request) {
  const access = await requireAdminSession(request, "owner");
  if (access instanceof Response) return access;
  try {
    const input = reviewSchema.parse(await request.json());
    await reviewWalletTopup({ ...input, adminEmail: access.email });
    if (input.decision === "approved") {
      await notifyWalletTopupSuccessById(input.id).catch((error) =>
        console.error("Notifikasi top up manual gagal:", error),
      );
    }
    return Response.json({ ok: true });
  } catch (error) {
    const message =
      error instanceof z.ZodError
        ? error.issues[0]?.message
        : error instanceof Error
          ? error.message
          : "Top up gagal ditinjau.";
    return Response.json({ error: message }, { status: 400 });
  }
}
