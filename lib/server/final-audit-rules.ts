export type FinalAuditMemberTier = "basic" | "gold" | "diamond" | "platinum";
export type FinalAuditAdminRole = "super_admin" | "admin" | "staff";
export type FinalAuditAccessLevel = "super_admin" | "admin" | "staff" | "owner";

export function resolveMemberTierFromProgress(progress: number): FinalAuditMemberTier {
  const value = Math.max(0, Number(progress) || 0);
  if (value >= 50_000_000) return "platinum";
  if (value >= 10_000_000) return "diamond";
  if (value >= 1_000_000) return "gold";
  return "basic";
}

export function hasMinimumAdminRole(
  role: FinalAuditAdminRole,
  minimumRole: FinalAuditAccessLevel,
) {
  if (minimumRole === "owner" || minimumRole === "super_admin") return role === "super_admin";
  if (minimumRole === "admin") return role === "super_admin" || role === "admin";
  return true;
}

function parseClock(value: string | null) {
  if (!value || !/^\d{2}:\d{2}$/.test(value)) return null;
  const [hour, minute] = value.split(":").map(Number);
  if (hour > 23 || minute > 59) return null;
  return hour * 60 + minute;
}

export function isCutoffActiveAtMinute(
  startCutOff: string | null,
  endCutOff: string | null,
  currentMinute: number,
) {
  const start = parseClock(startCutOff);
  const end = parseClock(endCutOff);
  if (start === null || end === null || start === end) return false;
  return start < end
    ? currentMinute >= start && currentMinute < end
    : currentMinute >= start || currentMinute < end;
}

export function isDigiflazzSnapshotAvailable(input: {
  buyerProductStatus: number | boolean;
  sellerProductStatus: number | boolean;
  unlimitedStock: number | boolean;
  stock: number;
  startCutOff: string | null;
  endCutOff: string | null;
  currentMinute: number;
}) {
  return Boolean(
    input.buyerProductStatus &&
    input.sellerProductStatus &&
    (input.unlimitedStock || Number(input.stock) > 0) &&
    !isCutoffActiveAtMinute(input.startCutOff, input.endCutOff, input.currentMinute),
  );
}

export function canProcessDokuOrderCallback(paymentStatus: string) {
  return paymentStatus === "pending";
}
