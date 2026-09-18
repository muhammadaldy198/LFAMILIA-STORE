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
  getIntegrationOverview,
  saveIntegrationProfile,
  saveIntegrationSelections,
} from "@/lib/server/integration-config";
import { syncDigiflazzPrices } from "@/lib/server/digiflazz-pricing";
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

const DIGIFLAZZ_CACHE_RECOVERY_ATTEMPTS = 10;
const DIGIFLAZZ_CACHE_RECOVERY_DELAY_MS = 500;

async function recoverDigiflazzOperationalCache() {
  let lastResult: Awaited<ReturnType<typeof syncDigiflazzPrices>> | null = null;
  for (let attempt = 1; attempt <= DIGIFLAZZ_CACHE_RECOVERY_ATTEMPTS; attempt += 1) {
    const result = await syncDigiflazzPrices({ force: true });
    lastResult = result;

    if (!result.skipped || Number(result.cached ?? 0) > 0) return result;
    if (attempt < DIGIFLAZZ_CACHE_RECOVERY_ATTEMPTS) {
      await new Promise((resolve) => setTimeout(resolve, DIGIFLAZZ_CACHE_RECOVERY_DELAY_MS));
    }
  }

  const reason = lastResult && "reason" in lastResult ? lastResult.reason : "unknown";
  throw new Error(`Cache operasional DigiFlazz belum pulih setelah retry terbatas (reason: ${reason}).`);
}

async function withDigiflazzConfigurationGuard(action: () => Promise<void>) {
  const token = await acquireDigiflazzConfigurationGuard();
  let successful = false;
  let failed = false;
  let failure: unknown;
  try {
    // The guard blocks new DigiFlazz orders and pricelist sync while the active
    // configuration changes. Invalidate credential-dependent caches before the
    // mutation so stale provider data can never survive a successful change.
    await invalidateDigiflazzOperationalCache(token);
    clearDigiflazzBalanceCache();
    await action();
    successful = true;
  } catch (error) {
    failed = true;
    failure = error;
  } finally {
    await releaseDigiflazzConfigurationGuard(token, successful);
  }

  if (failed) {
    // The mutation did not commit, so the previous active DigiFlazz profile is
    // still authoritative. Rebuild its operational cache immediately instead
    // of leaving checkout unavailable until the next scheduled sync.
    try {
      await recoverDigiflazzOperationalCache();
    } catch (rebuildError) {
      const primary = failure instanceof Error ? failure.message : String(failure);
      const recovery = rebuildError instanceof Error ? rebuildError.message : String(rebuildError);
      throw new Error(`${primary} Cache operasional DigiFlazz juga gagal dipulihkan: ${recovery}`);
    }
    throw failure;
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
          await withDigiflazzConfigurationGuard(() => saveIntegrationProfile(input).then(() => undefined));
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
        await withDigiflazzConfigurationGuard(() => saveIntegrationSelections(input.selections));
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
