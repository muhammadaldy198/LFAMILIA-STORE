import { z } from "zod";
import { requireAdminSession } from "@/lib/server/admin";
import {
  acquireDigiflazzConfigurationGuard,
  assertDigiflazzConfigurationIdle,
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

type DigiflazzRollbackPlan<T> = {
  rollback: (guardToken: string, committed: T) => Promise<boolean>;
};

async function withDigiflazzConfigurationGuard<T>(
  action: (guardToken: string) => Promise<T>,
  createRollbackPlan: () => Promise<DigiflazzRollbackPlan<T>>,
  shouldInvalidateOperationalCache: () => Promise<boolean> = async () => true,
) {
  const token = await acquireDigiflazzConfigurationGuard();
  let successful = false;
  let actionCommitted = false;
  let committed: T | null = null;
  let rollbackPlan: DigiflazzRollbackPlan<T> | null = null;
  try {
    const invalidateOperationalCache = await shouldInvalidateOperationalCache();
    rollbackPlan = await createRollbackPlan();
    committed = await action(token);
    actionCommitted = true;
    if (invalidateOperationalCache) {
      await invalidateDigiflazzOperationalCache(token);
      clearDigiflazzBalanceCache();
      successful = true;
    }
  } catch (error) {
    if (actionCommitted && rollbackPlan && committed !== null) {
      try {
        const restored = await rollbackPlan.rollback(token, committed);
        if (!restored) {
          throw new Error("Rollback dibatalkan karena guard kedaluwarsa atau konfigurasi DigiFlazz sudah berubah.");
        }
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
        const hasCredentialMutation =
          Object.values(input.values).some((value) => value.trim()) || input.clearFields.length > 0;
        await withDigiflazzConfigurationGuard(
          async (guardToken) => (await saveIntegrationProfile({ ...input, guardToken })).committedSnapshot,
          async () => {
            const snapshot = await captureIntegrationProfileSnapshot(input.provider, input.mode, input.environment);
            return {
              rollback: (guardToken: string, committed: Awaited<ReturnType<typeof captureIntegrationProfileSnapshot>>) =>
                restoreIntegrationProfileSnapshot(snapshot, committed, guardToken),
            };
          },
          async () => {
            const activeEnvironment = (await getIntegrationOverview()).selections.digiflazzEnvironment;
            return hasCredentialMutation && input.environment === activeEnvironment;
          },
        );
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
          async (guardToken) => {
            const result = await saveIntegrationSelections(input.selections, guardToken);
            if (!result.committedSnapshot) throw new Error("Pilihan environment DigiFlazz tidak tersimpan.");
            return result.committedSnapshot;
          },
          async () => {
            const snapshot = await captureIntegrationSettingSnapshot("digiflazz_environment");
            return {
              rollback: (guardToken: string, committed: Awaited<ReturnType<typeof captureIntegrationSettingSnapshot>>) =>
                restoreIntegrationSettingSnapshot(snapshot, committed, guardToken),
            };
          },
        );
      } else if (input.selections.digiflazzEnvironment) {
        await assertDigiflazzConfigurationIdle();
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
