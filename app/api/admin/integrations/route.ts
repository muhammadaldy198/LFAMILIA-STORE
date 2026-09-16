import { z } from "zod";
import { getD1 } from "@/db";
import { requireAdminSession } from "@/lib/server/admin";
import { dokuApiOrigin, testDokuB2BConnection } from "@/lib/server/doku-connection-test";
import {
  getIntegrationOverview,
  saveIntegrationProfile,
  saveIntegrationSelections,
} from "@/lib/server/integration-config";
import { testProviderRelayConnections } from "@/lib/server/provider-relay";

export const dynamic = "force-dynamic";

const profileInput = z.object({
  action: z.literal("save_profile"),
  provider: z.enum(["doku", "midtrans", "digiflazz", "kokinpay", "resend", "relay", "security"]),
  mode: z.enum(["direct", "service"]),
  environment: z.enum(["sandbox", "production", "development", "global"]),
  values: z.record(z.string().min(1).max(80), z.string().max(12_000)).default({}),
  clearFields: z.array(z.string().min(1).max(80)).max(32).default([]),
});

const selectionInput = z.object({
  action: z.literal("save_selections"),
  selections: z.object({
    dokuEnvironment: z.enum(["sandbox", "production"]).optional(),
    midtransEnvironment: z.enum(["sandbox", "production"]).optional(),
    digiflazzEnvironment: z.enum(["development", "production"]).optional(),
  }),
});

const relayTestInput = z.object({
  action: z.literal("test_relay"),
});

const dokuTestInput = z.object({
  action: z.literal("test_doku"),
  environment: z.enum(["sandbox", "production"]),
});

const schema = z.discriminatedUnion("action", [
  profileInput,
  selectionInput,
  relayTestInput,
  dokuTestInput,
]);

async function invalidateDigiflazzOperationalCache() {
  const db = getD1();
  for (const sql of [
    "DELETE FROM digiflazz_pricelist_cache",
    "DELETE FROM digiflazz_seller_monitor",
    "UPDATE digiflazz_pricelist_sync_state SET lock_token = NULL, locked_until = NULL, last_success_at = NULL WHERE id = 1",
  ]) {
    try {
      await db.prepare(sql).run();
    } catch (error) {
      const message = error instanceof Error ? error.message : String(error);
      if (!/no such table/i.test(message)) throw error;
    }
  }
}

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
    if (input.action === "test_doku") {
      return Response.json(
        { ok: true, doku: await testDokuB2BConnection(input.environment) },
        { headers: { "Cache-Control": "no-store" } },
      );
    }
    if (input.action === "save_profile") {
      if (input.provider === "doku" && (input.environment === "sandbox" || input.environment === "production")) {
        await saveIntegrationProfile({
          ...input,
          values: {
            ...input.values,
            apiUrl: dokuApiOrigin(input.environment),
          },
        });
      } else {
        await saveIntegrationProfile(input);
      }
    } else {
      const before = input.selections.digiflazzEnvironment
        ? (await getIntegrationOverview()).selections.digiflazzEnvironment
        : null;
      await saveIntegrationSelections(input.selections);
      if (input.selections.digiflazzEnvironment && before !== input.selections.digiflazzEnvironment) {
        await invalidateDigiflazzOperationalCache();
      }
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
