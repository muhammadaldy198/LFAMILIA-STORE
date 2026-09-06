import { z } from "zod";
import { requireAdminSession } from "@/lib/server/admin";
import {
  getIntegrationOverview,
  saveIntegrationProfile,
  saveIntegrationSelections,
} from "@/lib/server/integration-config";
import { testProviderRelayConnections } from "@/lib/server/provider-relay";

export const dynamic = "force-dynamic";

const profileInput = z.object({
  action: z.literal("save_profile"),
  provider: z.enum(["midtrans", "ipaymu", "digiflazz", "vippayment", "melostore", "resend", "relay", "security"]),
  mode: z.enum(["snap", "bisnap", "direct", "service"]),
  environment: z.enum(["sandbox", "production", "development", "global"]),
  values: z.record(z.string().min(1).max(80), z.string().max(8_000)).default({}),
  clearFields: z.array(z.string().min(1).max(80)).max(24).default([]),
});

const selectionInput = z.object({
  action: z.literal("save_selections"),
  selections: z.object({
    midtransMode: z.enum(["snap", "bisnap"]).optional(),
    midtransEnvironment: z.enum(["sandbox", "production"]).optional(),
    ipaymuEnvironment: z.enum(["sandbox", "production"]).optional(),
    digiflazzEnvironment: z.enum(["development", "production"]).optional(),
    vippaymentEnvironment: z.enum(["sandbox", "production"]).optional(),
  }),
});

const relayTestInput = z.object({
  action: z.literal("test_relay"),
});

const schema = z.discriminatedUnion("action", [profileInput, selectionInput, relayTestInput]);

export async function GET(request: Request) {
  const access = await requireAdminSession(request, "owner");
  if (access instanceof Response) return access;
  try {
    return Response.json(await getIntegrationOverview(), {
      headers: { "Cache-Control": "no-store" },
    });
  } catch (error) {
    return Response.json(
      { error: error instanceof Error ? error.message : "Pengaturan integrasi gagal dimuat." },
      { status: 503 },
    );
  }
}

export async function PUT(request: Request) {
  const access = await requireAdminSession(request, "owner");
  if (access instanceof Response) return access;
  try {
    const input = schema.parse(await request.json());
    if (input.action === "test_relay") {
      return Response.json(
        { ok: true, relay: await testProviderRelayConnections() },
        { headers: { "Cache-Control": "no-store" } },
      );
    }
    if (input.action === "save_profile") {
      await saveIntegrationProfile(input);
    } else {
      await saveIntegrationSelections(input.selections);
    }
    return Response.json({ ok: true, overview: await getIntegrationOverview() });
  } catch (error) {
    const message = error instanceof z.ZodError
      ? error.issues[0]?.message || "Data integrasi tidak valid."
      : error instanceof Error
        ? error.message
        : "Pengaturan integrasi gagal disimpan.";
    return Response.json({ error: message }, { status: 400 });
  }
}
