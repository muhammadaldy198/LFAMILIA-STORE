import { z } from "zod";
import { requireAdminSession } from "@/lib/server/admin";
import {
  cleanupDormantCustomerAccounts,
  getCustomerCleanupSettings,
  saveCustomerCleanupSettings,
} from "@/lib/server/customer-cleanup";

export const dynamic = "force-dynamic";

const settingsSchema = z.object({
  enabled: z.boolean(),
  inactivityDays: z.number().int().min(7).max(365),
});

export async function GET(request: Request) {
  const access = await requireAdminSession(request, "owner");
  if (access instanceof Response) return access;
  try {
    return Response.json({ settings: await getCustomerCleanupSettings() });
  } catch (error) {
    return Response.json(
      { error: error instanceof Error ? error.message : "Pengaturan pembersihan akun gagal dimuat." },
      { status: 503 },
    );
  }
}

export async function PUT(request: Request) {
  const access = await requireAdminSession(request, "owner");
  if (access instanceof Response) return access;
  try {
    const input = settingsSchema.parse(await request.json());
    return Response.json({ ok: true, settings: await saveCustomerCleanupSettings(input) });
  } catch (error) {
    const message = error instanceof z.ZodError
      ? error.issues[0]?.message || "Pengaturan pembersihan akun tidak valid."
      : error instanceof Error
        ? error.message
        : "Pengaturan pembersihan akun gagal disimpan.";
    return Response.json({ error: message }, { status: 400 });
  }
}

export async function POST(request: Request) {
  const access = await requireAdminSession(request, "owner");
  if (access instanceof Response) return access;
  try {
    const result = await cleanupDormantCustomerAccounts({ force: true });
    return Response.json({ ok: true, ...result });
  } catch (error) {
    return Response.json(
      { error: error instanceof Error ? error.message : "Pembersihan akun gagal dijalankan." },
      { status: 503 },
    );
  }
}
