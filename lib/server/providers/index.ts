import { digiflazzAdapter } from "@/lib/server/providers/digiflazz";
import type { ProviderAdapter } from "@/lib/server/providers/types";
import { voucherStockAdapter } from "@/lib/server/providers/voucher-stock";

const adapters = new Map<string, ProviderAdapter>([
  [digiflazzAdapter.code, digiflazzAdapter],
  [voucherStockAdapter.code, voucherStockAdapter],
]);

export function getProviderAdapter(code: string) {
  return adapters.get(code) ?? null;
}
