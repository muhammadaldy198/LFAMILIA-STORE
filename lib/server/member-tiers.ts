import { getD1 } from "@/db";

export type MemberTier = "basic" | "gold" | "diamond" | "platinum";

export type MemberTierSetting = {
  tier: MemberTier;
  label: string;
  minSpend: number;
  discountPercent: number;
  benefits: string;
};

export type MemberTierProfile = {
  tier: MemberTier;
  label: string;
  lifetimeSpend: number;
  nextTier: MemberTier | null;
  nextTierLabel: string | null;
  nextTarget: number | null;
  remainingToNextTier: number;
  setting: MemberTierSetting;
};

export const MEMBER_TIER_DEFINITIONS: ReadonlyArray<{
  tier: MemberTier;
  label: string;
  minSpend: number;
}> = [
  { tier: "basic", label: "BASIC", minSpend: 0 },
  { tier: "gold", label: "GOLD", minSpend: 1_000_000 },
  { tier: "diamond", label: "DIAMOND", minSpend: 10_000_000 },
  { tier: "platinum", label: "PLATINUM", minSpend: 50_000_000 },
];

let ensurePromise: Promise<void> | null = null;

async function ensureMemberTierSettings() {
  if (!ensurePromise) {
    ensurePromise = (async () => {
      const db = getD1();
      await db
        .prepare(
          `CREATE TABLE IF NOT EXISTS member_tier_settings (
            tier TEXT PRIMARY KEY,
            discount_percent REAL NOT NULL DEFAULT 0,
            benefits TEXT NOT NULL DEFAULT '',
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
          )`,
        )
        .run();
      for (const definition of MEMBER_TIER_DEFINITIONS) {
        await db
          .prepare(
            `INSERT OR IGNORE INTO member_tier_settings (tier, discount_percent, benefits)
             VALUES (?, 0, '')`,
          )
          .bind(definition.tier)
          .run();
      }
    })().catch((error) => {
      ensurePromise = null;
      throw error;
    });
  }
  return ensurePromise;
}

export function resolveMemberTier(lifetimeSpend: number): MemberTier {
  const safeSpend = Math.max(0, Number(lifetimeSpend) || 0);
  if (safeSpend >= 50_000_000) return "platinum";
  if (safeSpend >= 10_000_000) return "diamond";
  if (safeSpend >= 1_000_000) return "gold";
  return "basic";
}

function definitionFor(tier: MemberTier) {
  return MEMBER_TIER_DEFINITIONS.find((item) => item.tier === tier)!;
}

export async function listMemberTierSettings(): Promise<MemberTierSetting[]> {
  await ensureMemberTierSettings();
  const result = await getD1()
    .prepare(
      `SELECT tier, discount_percent, benefits
       FROM member_tier_settings`,
    )
    .all<{ tier: MemberTier; discount_percent: number; benefits: string }>();
  const rows = new Map(result.results.map((row) => [row.tier, row]));
  return MEMBER_TIER_DEFINITIONS.map((definition) => {
    const row = rows.get(definition.tier);
    return {
      ...definition,
      discountPercent: Math.max(0, Math.min(100, Number(row?.discount_percent ?? 0))),
      benefits: row?.benefits ?? "",
    };
  });
}

export async function saveMemberTierSettings(
  settings: Array<Pick<MemberTierSetting, "tier" | "discountPercent" | "benefits">>,
) {
  await ensureMemberTierSettings();
  const allowed = new Set(MEMBER_TIER_DEFINITIONS.map((item) => item.tier));
  const db = getD1();
  for (const item of settings) {
    if (!allowed.has(item.tier)) continue;
    const discountPercent = Math.max(0, Math.min(100, Number(item.discountPercent) || 0));
    await db
      .prepare(
        `UPDATE member_tier_settings
         SET discount_percent = ?, benefits = ?, updated_at = CURRENT_TIMESTAMP
         WHERE tier = ?`,
      )
      .bind(discountPercent, item.benefits.trim(), item.tier)
      .run();
  }
}

export async function getMemberLifetimeSpend(customerId: string) {
  const row = await getD1()
    .prepare(
      `SELECT COALESCE(SUM(total), 0) AS lifetime_spend
       FROM orders
       WHERE customer_id = ? AND payment_status = 'paid'`,
    )
    .bind(customerId)
    .first<{ lifetime_spend: number }>();
  return Number(row?.lifetime_spend ?? 0);
}

export async function getMemberTierProfile(customerId: string): Promise<MemberTierProfile> {
  const [lifetimeSpend, settings] = await Promise.all([
    getMemberLifetimeSpend(customerId),
    listMemberTierSettings(),
  ]);
  const tier = resolveMemberTier(lifetimeSpend);
  const index = MEMBER_TIER_DEFINITIONS.findIndex((item) => item.tier === tier);
  const current = MEMBER_TIER_DEFINITIONS[index];
  const next = MEMBER_TIER_DEFINITIONS[index + 1] ?? null;
  return {
    tier,
    label: current.label,
    lifetimeSpend,
    nextTier: next?.tier ?? null,
    nextTierLabel: next?.label ?? null,
    nextTarget: next?.minSpend ?? null,
    remainingToNextTier: next ? Math.max(0, next.minSpend - lifetimeSpend) : 0,
    setting: settings.find((item) => item.tier === tier) ?? {
      ...current,
      discountPercent: 0,
      benefits: "",
    },
  };
}

export async function listMembersWithTiers(limit = 300) {
  await ensureMemberTierSettings();
  const result = await getD1()
    .prepare(
      `SELECT u.id, u.name, u.email, u.phone, u.balance, u.is_active, u.created_at,
        COALESCE(SUM(CASE WHEN o.payment_status = 'paid' THEN o.total ELSE 0 END), 0) AS lifetime_spend,
        COALESCE(SUM(CASE WHEN o.payment_status = 'paid' THEN 1 ELSE 0 END), 0) AS paid_orders
       FROM customer_users u
       LEFT JOIN orders o ON o.customer_id = u.id
       GROUP BY u.id, u.name, u.email, u.phone, u.balance, u.is_active, u.created_at
       ORDER BY lifetime_spend DESC, u.created_at DESC
       LIMIT ?`,
    )
    .bind(Math.min(Math.max(limit, 1), 500))
    .all<{
      id: string;
      name: string;
      email: string;
      phone: string;
      balance: number;
      is_active: number;
      created_at: string;
      lifetime_spend: number;
      paid_orders: number;
    }>();
  return result.results.map((row) => {
    const lifetimeSpend = Number(row.lifetime_spend ?? 0);
    const tier = resolveMemberTier(lifetimeSpend);
    return {
      id: row.id,
      name: row.name,
      email: row.email,
      phone: row.phone,
      balance: Number(row.balance ?? 0),
      isActive: Boolean(row.is_active),
      createdAt: row.created_at,
      lifetimeSpend,
      paidOrders: Number(row.paid_orders ?? 0),
      tier,
      tierLabel: definitionFor(tier).label,
    };
  });
}
