import { getD1 } from "@/db";
import type { ProviderAdapter } from "@/lib/server/providers/types";
import {
  fulfillVoucherStockOrder,
  revealVoucherCode,
} from "@/lib/server/vouchers";

export const voucherStockAdapter: ProviderAdapter = {
  code: "voucher-stock",
  name: "Stok Kode Internal",
  async fulfill(order) {
    try {
      return await fulfillVoucherStockOrder(order);
    } catch (deliveryError) {
      const voucher = await revealVoucherCode(order.id).catch(() => null);
      if (!voucher) throw deliveryError;

      await getD1()
        .prepare(
          `UPDATE voucher_codes
           SET status = 'delivered', delivered_at = COALESCE(delivered_at, CURRENT_TIMESTAMP)
           WHERE id = ? AND order_id = ?`,
        )
        .bind(voucher.codeId, order.id)
        .run();

      return {
        externalId: `stock-${voucher.codeId}`,
        status: "success",
        message: "Kode tersedia langsung di akun LFAMILIA.",
        serialNumber: `STOCK-${voucher.codeId}`,
        raw: {
          voucherCodeId: voucher.codeId,
          stockKey: voucher.stockKey,
          websiteDelivery: true,
          externalDeliveryError:
            deliveryError instanceof Error
              ? deliveryError.message
              : "Pengiriman eksternal gagal.",
        },
      };
    }
  },
};
