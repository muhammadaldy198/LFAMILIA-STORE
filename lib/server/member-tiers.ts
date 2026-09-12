import { getD1 } from "@/db";
import { ensureLegacyDatabaseColumns } from "@/lib/server/database-repair";

export type MemberTier = "basic" | "gold" | "diamond" | "platinum";
export type MemberTierMode = "automatic" | "manual";

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
  tierProgress: number;
  tierProgressBonus: number;
  tierMode: MemberTierMode;
  tierOverride: MemberTier | null;
  nextTier: MemberTier | null;
  nextTierLabel: string | null;
  nextTarget: number | null;
  remainingToNextTier: number;
  setting: MemberTierSetting;
};

export const MEMBER_TIER_DEFINITIONS: ReadonlyArray<{ tier: MemberTier; label: string; minSpend: number }> = [
  { tier: "basic", label: "BASIC", minSpend: 0 },
  { tier: "gold", label: "GOLD", minSpend: 1_000_000 },
  { tier: "diamond", label: "DIAMOND", minSpend: 10_000_000 },
  { tier: "platinum", label: "PLATINUM", minSpend: 50_000_000 },
];

let ensurePromise: Promise<void> | null = null;

async function ensureMemberTierSettings() {
  if (!ensurePromise) {
    ensurePromise = (async () => {
      await ensureLegacyDatabaseColumns();
      const db = getD1();
      await db.prepare(`CREATE TABLE IF NOT EXISTS member_tier_settings (
        tier TEXT PRIMARY KEY,
        discount_percent REAL NOT NULL DEFAULT 0,
        benefits TEXT NOT NULL DEFAULT '',
        updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
      )`).run();
      for (const definition of MEMBER_TIER_DEFINITIONS) {
        await db.prepare(`INSERT OR IGNORE INTO member_tier_settings (tier, discount_percent, benefits) VALUES (?, 0, '')`).bind(definition.tier).run();
      }
    })().catch((error) => { ensurePromise = null; throw error; });
  }
  return ensurePromise;
}

export function resolveMemberTier(progress: number): MemberTier {
  const value = Math.max(0, Number(progress) || 0);
  if (value >= 50_000_000) return "platinum";
  if (value >= 10_000_000) return "diamond";
  if (value >= 1_000_000) return "gold";
  return "basic";
}

function isMemberTier(value: unknown): value is MemberTier {
  return value === "basic" || value === "gold" || value === "diamond" || value === "platinum";
}
function definitionFor(tier: MemberTier) {
  return MEMBER_TIER_DEFINITIONS.find((item) => item.tier === tier)!;
}

export async function listMemberTierSettings(): Promise<MemberTierSetting[]> {
  await ensureMemberTierSettings();
  const result = await getD1().prepare(`SELECT tier, discount_percent, benefits FROM member_tier_settings`).all<{ tier: MemberTier; discount_percent: number; benefits: string }>();
  const rows = new Map(result.results.map((row) => [row.tier, row]));
  return MEMBER_TIER_DEFINITIONS.map((definition) => {
    const row = rows.get(definition.tier);
    return { ...definition, discountPercent: Math.max(0, Math.min(100, Number(row?.discount_percent ?? 0))), benefits: row?.benefits ?? "" };
  });
}

export async function saveMemberTierSettings(settings: Array<Pick<MemberTierSetting, "tier" | "discountPercent" | "benefits">>) {
  await ensureMemberTierSettings();
  const allowed = new Set(MEMBER_TIER_DEFINITIONS.map((item) => item.tier));
  const db = getD1();
  for (const item of settings) {
    if (!allowed.has(item.tier)) continue;
    await db.prepare(`UPDATE member_tier_settings SET discount_percent = ?, benefits = ?, updated_at = CURRENT_TIMESTAMP WHERE tier = ?`)
      .bind(Math.max(0, Math.min(100, Number(item.discountPercent) || 0)), item.benefits.trim(), item.tier).run();
  }
}

export async function getMemberLifetimeSpend(customerId: string) {
  const row = await getD1().prepare(`SELECT COALESCE(SUM(total), 0) AS lifetime_spend FROM orders WHERE customer_id = ? AND payment_status = 'paid'`)
    .bind(customerId).first<{ lifetime_spend: number }>();
  return Number(row?.lifetime_spend ?? 0);
}

async function getRoleState(customerId: string) {
  await ensureMemberTierSettings();
  const row = await getD1().prepare(`SELECT tier_mode, tier_override, tier_progress_bonus FROM customer_users WHERE id = ? AND email NOT LIKE '__lfadmin__:%' LIMIT 1`)
    .bind(customerId).first<{ tier_mode: string; tier_override: string | null; tier_progress_bonus: number }>();
  if (!row) throw new Error("Pelanggan tidak ditemukan.");
  return {
    tierMode: row.tier_mode === "manual" ? "manual" as const : "automatic" as const,
    tierOverride: isMemberTier(row.tier_override) ? row.tier_override : null,
    tierProgressBonus: Math.max(0, Number(row.tier_progress_bonus ?? 0)),
  };
}

export async function getMemberTierProfile(customerId: string): Promise<MemberTierProfile> {
  await ensureMemberTierSettings();
  const [lifetimeSpend, settings, role] = await Promise.all([
    getMemberLifetimeSpend(customerId), listMemberTierSettings(), getRoleState(customerId),
  ]);
  const tierProgress = lifetimeSpend + role.tierProgressBonus;
  const automaticTier = resolveMemberTier(tierProgress);
  const tier = role.tierMode === "manual" && role.tierOverride ? role.tierOverride : automaticTier;
  const index = MEMBER_TIER_DEFINITIONS.findIndex((item) => item.tier === tier);
  const current = MEMBER_TIER_DEFINITIONS[index];
  const next = MEMBER_TIER_DEFINITIONS[index + 1] ?? null;
  return {
    tier, label: current.label, lifetimeSpend, tierProgress, tierProgressBonus: role.tierProgressBonus,
    tierMode: role.tierMode, tierOverride: role.tierOverride,
    nextTier: next?.tier ?? null, nextTierLabel: next?.label ?? null, nextTarget: next?.minSpend ?? null,
    remainingToNextTier: next ? Math.max(0, next.minSpend - tierProgress) : 0,
    setting: settings.find((item) => item.tier === tier) ?? { ...current, discountPercent: 0, benefits: "" },
  };
}

export async function setMemberRole(customerId: string, role: "automatic" | MemberTier) {
  await ensureMemberTierSettings();
  const db = getD1();
  const current = await getRoleState(customerId);
  if (role === "automatic") {
    await db.prepare(`UPDATE customer_users SET tier_mode = 'automatic', tier_override = NULL, tier_progress_bonus = 0, updated_at = CURRENT_TIMESTAMP WHERE id = ?`).bind(customerId).run();
    return;
  }
  const lifetimeSpend = await getMemberLifetimeSpend(customerId);
  const minimum = definitionFor(role).minSpend;
  const currentProgress = lifetimeSpend + current.tierProgressBonus;
  const bonus = current.tierProgressBonus + Math.max(0, minimum - currentProgress);
  await db.prepare(`UPDATE customer_users SET tier_mode = 'manual', tier_override = ?, tier_progress_bonus = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?`)
    .bind(role, bonus, customerId).run();
}

export async function addMemberBalance(input: { customerId: string; amount: number; adminEmail: string; reason?: string }) {
  await ensureMemberTierSettings();
  const amount = Math.trunc(Number(input.amount));
  if (!Number.isFinite(amount) || amount <= 0 || amount > 100_000_000) throw new Error("Nominal saldo tidak valid.");
  const db = getD1();
  const customer = await db.prepare("SELECT id FROM customer_users WHERE id = ? AND email NOT LIKE '__lfadmin__:%' LIMIT 1").bind(input.customerId).first<{ id: string }>();
  if (!customer) throw new Error("Pelanggan tidak ditemukan.");
  const reference = `admin-credit:${crypto.randomUUID()}`;
  const description = input.reason?.trim() ? `Penambahan saldo admin: ${input.reason.trim()}` : `Penambahan saldo oleh ${input.adminEmail}`;
  await db.batch([
    db.prepare(`INSERT INTO wallet_transactions (id, customer_id, direction, amount, balance_before, balance_after, reference, description)
      SELECT ?, ?, 'credit', ?, ledger.balance, ledger.balance + ?, ?, ?
      FROM (SELECT COALESCE(SUM(CASE WHEN direction = 'credit' THEN amount ELSE -amount END), 0) AS balance FROM wallet_transactions WHERE customer_id = ?) ledger`)
      .bind(crypto.randomUUID(), input.customerId, amount, amount, reference, description, input.customerId),
    db.prepare(`UPDATE customer_users SET balance = (SELECT COALESCE(SUM(CASE WHEN direction = 'credit' THEN amount ELSE -amount END), 0) FROM wallet_transactions WHERE customer_id = ?), updated_at = CURRENT_TIMESTAMP WHERE id = ?`)
      .bind(input.customerId, input.customerId),
  ]);
}

export async function listMembersWithTiers(limit = 300) {
  await ensureMemberTierSettings();
  const result = await getD1().prepare(`SELECT u.id, u.name, u.email, u.phone, u.balance, u.is_active, u.created_at,
      u.tier_mode, u.tier_override, u.tier_progress_bonus,
      COALESCE(SUM(CASE WHEN o.payment_status = 'paid' THEN o.total ELSE 0 END), 0) AS lifetime_spend,
      COALESCE(SUM(CASE WHEN o.payment_status = 'paid' THEN 1 ELSE 0 END), 0) AS paid_orders
    FROM customer_users u LEFT JOIN orders o ON o.customer_id = u.id
    WHERE u.email NOT LIKE '__lfadmin__:%'
    GROUP BY u.id, u.name, u.email, u.phone, u.balance, u.is_active, u.created_at, u.tier_mode, u.tier_override, u.tier_progress_bonus
    ORDER BY lifetime_spend DESC, u.created_at DESC LIMIT ?`)
    .bind(Math.min(Math.max(limit, 1), 500))
    .all<{ id:string;name:string;email:string;phone:string;balance:number;is_active:number;created_at:string;tier_mode:string;tier_override:string|null;tier_progress_bonus:number;lifetime_spend:number;paid_orders:number }>();

  return result.results.map((row) => {
    const lifetimeSpend = Number(row.lifetime_spend ?? 0);
    const tierProgressBonus = Math.max(0, Number(row.tier_progress_bonus ?? 0));
    const tierProgress = lifetimeSpend + tierProgressBonus;
    const tierOverride = isMemberTier(row.tier_override) ? row.tier_override : null;
    const tierMode: MemberTierMode = row.tier_mode === "manual" ? "manual" : "automatic";
    const tier = tierMode === "manual" && tierOverride ? tierOverride : resolveMemberTier(tierProgress);
    return {
      id: row.id, name: row.name, email: row.email, phone: row.phone, balance: Number(row.balance ?? 0),
      isActive: Boolean(row.is_active), createdAt: row.created_at, lifetimeSpend, paidOrders: Number(row.paid_orders ?? 0),
      tierProgress, tierProgressBonus, tierMode, tierOverride, tier, tierLabel: definitionFor(tier).label,
    };
  });
}
