import type { ProviderAdapter } from "@/lib/server/providers/types";
import { fulfillVoucherStockOrder } from "@/lib/server/vouchers";

export const voucherStockAdapter: ProviderAdapter = {
  code: "voucher-stock",
  name: "Stok Kode Internal",
  fulfill(order) {
    return fulfillVoucherStockOrder(order);
  },
};
