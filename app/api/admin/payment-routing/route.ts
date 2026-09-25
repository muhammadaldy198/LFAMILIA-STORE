import { z } from "zod";
import { requireAdminSession } from "@/lib/server/admin";
import {
  getPaymentModeOverview,
  savePaymentGatewayProfile,
  savePaymentModeSelections,
} from "@/lib/server/payment-mode-config";

export const dynamic = "force-dynamic";

const saveModes = z.object({
  action: z.literal("save_modes"),
  dokuEnvironment: z.enum(["sandbox", "production"]),
  midtransEnvironment: z.enum(["sandbox", "production"]),
  walletTopupGateway: z.enum(["doku", "midtrans"]),
});

const saveProfile = z.object({
  action: z.literal("save_profile"),
  provider: z.enum(["doku", "midtrans"]),
  mode: z.enum(["checkout", "snap"]),
  environment: z.enum(["sandbox", "production"]),
  values: z.record(z.string().min(1).max(40), z.string().max(20_000)),
});

const schema = z.discriminatedUnion("action", [saveModes, saveProfile]);

export async function GET(request: Request) {
  const access = await requireAdminSession(request, "admin");
  if (access instanceof Response) return access;
  return Response.json(await getPaymentModeOverview(), {
    headers: { "Cache-Control": "no-store" },
  });
}

export async function PUT(request: Request) {
  const access = await requireAdminSession(request, "admin");
  if (access instanceof Response) return access;
  try {
    const input = schema.parse(await request.json());
    if (input.action === "save_modes") {
      await savePaymentModeSelections({
        dokuEnvironment: input.dokuEnvironment,
        midtransEnvironment: input.midtransEnvironment,
        walletTopupGateway: input.walletTopupGateway,
      });
    } else {
      const ownerAccess = await requireAdminSession(request, "owner");
      if (ownerAccess instanceof Response) return ownerAccess;
      if ((input.provider === "doku" && input.mode !== "checkout") || (input.provider === "midtrans" && input.mode !== "snap")) {
        throw new Error("Kombinasi gateway dan mode tidak valid.");
      }
      await savePaymentGatewayProfile(input);
    }
    return Response.json({ ok: true, overview: await getPaymentModeOverview() });
  } catch (error) {
    return Response.json({
      error: error instanceof z.ZodError
        ? error.issues[0]?.message || "Data routing pembayaran tidak valid."
        : error instanceof Error
          ? error.message
          : "Routing pembayaran gagal disimpan.",
    }, { status: 400 });
  }
}
