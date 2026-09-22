export function normalizeWhatsappPhone(value: string) {
  const compact = value.trim().replace(/[\s().-]/g, "");
  const digits = compact.replace(/^\+/, "").replace(/\D/g, "");
  if (!digits) throw new Error("Nomor kontak tidak valid.");

  let normalized = digits;
  if (digits.startsWith("0")) normalized = `62${digits.slice(1)}`;
  else if (!digits.startsWith("62")) normalized = digits;

  if (!/^62[1-9][0-9]{7,13}$/.test(normalized)) {
    throw new Error("Gunakan nomor kontak Indonesia yang valid, misalnya 081234567890.");
  }
  return `+${normalized}`;
}

export function maskPhone(value: string) {
  const normalized = normalizeWhatsappPhone(value);
  const visibleStart = normalized.slice(0, 5);
  const visibleEnd = normalized.slice(-3);
  return `${visibleStart}${"•".repeat(Math.max(4, normalized.length - 8))}${visibleEnd}`;
}
