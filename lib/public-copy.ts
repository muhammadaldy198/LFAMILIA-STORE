const INTERNAL_PROVIDER_NAMES = /\b(?:doku|digiflazz|melostore)\b/gi;

/**
 * Customer-facing copy must stay provider-neutral even when an old Admin/D1
 * value still contains an integration brand name.
 */
export function neutralizePublicCopy(value: string) {
  return value.replace(INTERNAL_PROVIDER_NAMES, "sistem LFAMILIA");
}
