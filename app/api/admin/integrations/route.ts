import { z } from "zod";
import { requireAdminSession } from "@/lib/server/admin";
import {
  acquireDigiflazzConfigurationGuard,
  invalidateDigiflazzOperationalCache,
  releaseDigiflazzConfigurationGuard,
} from "@/lib/server/digiflazz-config-guard";
import {
  clearDigiflazzBalanceCache,
  getDigiflazzBalance,
} from "@/lib/server/providers/digiflazz";
import {
  captureIntegrationProfileSnapshot,
  captureIntegrationSettingSnapshot,
  getIntegrationOverview,
  restoreIntegrationProfileSnapshot,
  restoreIntegrationSettingSnapshot,
  saveIntegrationProfile,
  saveIntegrationSelections,
} from "@/lib/server/integration-config";
import { testProviderRelayConnections } from "@/lib/server/provider-relay";

export const dynamic = "force-dynamic";

const profileInput = z.object({
  action: z.literal("save_profile"),
  provider: z.enum(["digiflazz", "kokinpay", "google", "whatsapp", "resend", "relay", "security"]),
  mode: z.enum(["direct", "service"]),
  environment: z.enum(["sandbox", "production", "development", "global"]),
  values: z.record(z.string().min(1).max(80), z.string().max(12_000)).default({}),
  clearFields: z.array(z.string().min(1).max(80)).max(32).default([]),
});

const selectionInput = z.object({
  action: z.literal("save_selections"),
  selections: z.object({
    digiflazzEnvironment: z.enum(["development", "production"]).optional(),
  }),
});

const relayTestInput = z.object({
  action: z.literal("test_relay"),
});


const digiflazzTestInput = z.object({
  action: z.literal("test_digiflazz"),
});

const schema = z.discriminatedUnion("action", [
  profileInput,
  selectionInput,
  relayTestInput,
  digiflazzTestInput,
]);

type DigiflazzRollback = () => Promise<void>;

async function withDigiflazzConfigurationGuard(
  action: () => Promise<void>,
  captureRollback: () => Promise<DigiflazzRollback>,
) {
  const token = await acquireDigiflazzConfigurationGuard();
  let successful = false;
  let actionCommitted = false;
  let rollback: DigiflazzRollback | null = null;
  try {
    // Snapshot the small configuration record while the guard is held. The
    // credential mutation happens before destructive cache invalidation, so a
    // failed save leaves the old cache untouched. Cache invalidation itself is
    // atomic; if it fails after the save, restore the previous configuration
    // before the guard is released.
    rollback = await captureRollback();
    await action();
    actionCommitted = true;
    await invalidateDigiflazzOperationalCache(token);
    clearDigiflazzBalanceCache();
    successful = true;
  } catch (error) {
    if (actionCommitted && rollback) {
      try {
        await rollback();
      } catch (rollbackError) {
        const primary = error instanceof Error ? error.message : String(error);
        const recovery = rollbackError instanceof Error ? rollbackError.message : String(rollbackError);
        throw new Error(`${primary} Konfigurasi DigiFlazz lama juga gagal dipulihkan: ${recovery}`);
      }
    }
    throw error;
  } finally {
    await releaseDigiflazzConfigurationGuard(token, successful);
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
    if (input.action === "test_digiflazz") {
      clearDigiflazzBalanceCache();
      const result = await getDigiflazzBalance();
      return Response.json(
        { ok: true, digiflazz: { connected: true, balance: result.balance } },
        { headers: { "Cache-Control": "no-store" } },
      );
    }
    if (input.action === "save_profile") {
      if (input.provider === "digiflazz" && (input.environment === "development" || input.environment === "production")) {
        const activeEnvironment = (await getIntegrationOverview()).selections.digiflazzEnvironment;
        const activeProfileChanged = input.environment === activeEnvironment &&
          (Object.values(input.values).some((value) => value.trim()) || input.clearFields.length > 0);
        if (activeProfileChanged) {
          await withDigiflazzConfigurationGuard(
            () => saveIntegrationProfile(input).then(() => undefined),
            async () => {
              const snapshot = await captureIntegrationProfileSnapshot(input.provider, input.mode, input.environment);
              return () => restoreIntegrationProfileSnapshot(snapshot);
            },
          );
        } else {
          await saveIntegrationProfile(input);
        }
      } else {
        await saveIntegrationProfile(input);
      }
    } else {
      const before = input.selections.digiflazzEnvironment
        ? (await getIntegrationOverview()).selections.digiflazzEnvironment
        : null;
      const changingDigiflazzEnvironment = Boolean(
        input.selections.digiflazzEnvironment && before !== input.selections.digiflazzEnvironment,
      );
      if (changingDigiflazzEnvironment) {
        await withDigiflazzConfigurationGuard(
          () => saveIntegrationSelections(input.selections),
          async () => {
            const snapshot = await captureIntegrationSettingSnapshot("digiflazz_environment");
            return () => restoreIntegrationSettingSnapshot(snapshot);
          },
        );
      } else {
        await saveIntegrationSelections(input.selections);
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
