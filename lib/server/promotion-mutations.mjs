export async function saveDiscountVoucherMutation(db, input, id) {
  const normalizedCode = input.code.toUpperCase();
  const values = [
    normalizedCode,
    input.name,
    input.description,
    input.discountType,
    input.discountValue,
    input.minPurchase,
    input.maxDiscount,
    input.usageLimit,
    input.startsAt,
    input.endsAt,
    input.isActive ? 1 : 0,
  ];

  if (id) {
    const current = await db.prepare(
      "SELECT code, used_count, reserved_count FROM discount_vouchers WHERE id = ? LIMIT 1",
    ).bind(id).first();
    if (!current) throw new Error("Voucher diskon tidak ditemukan.");

    const renaming = current.code !== normalizedCode;
    if (renaming) {
      if (current.used_count > 0) {
        throw new Error("Kode voucher tidak dapat diubah setelah pernah digunakan. Ubah nama/deskripsi, atau buat voucher baru.");
      }

      const [currentHistory, targetHistory] = await Promise.all([
        db.prepare(
          "SELECT 1 AS found FROM promotion_reservations WHERE voucher_code = ? LIMIT 1",
        ).bind(current.code).first(),
        db.prepare(
          "SELECT 1 AS found FROM promotion_reservations WHERE voucher_code = ? LIMIT 1",
        ).bind(normalizedCode).first(),
      ]);

      if (current.reserved_count > 0 || currentHistory) {
        throw new Error("Kode voucher tidak dapat diubah setelah dipakai atau direservasi. Ubah nama/deskripsi, atau buat voucher baru.");
      }
      if (targetHistory) {
        throw new Error("Kode voucher pernah dipakai oleh transaksi lain. Gunakan kode baru agar riwayat promo tidak tertukar.");
      }
    }

    if (input.usageLimit !== null && input.usageLimit < current.used_count + current.reserved_count) {
      throw new Error("Batas penggunaan tidak boleh lebih kecil dari penggunaan + reservasi aktif.");
    }

    const result = await db.prepare(
      `UPDATE discount_vouchers
       SET code = ?, name = ?, description = ?, discount_type = ?, discount_value = ?,
           min_purchase = ?, max_discount = ?, usage_limit = ?, starts_at = ?, ends_at = ?,
           is_active = ?, updated_at = CURRENT_TIMESTAMP
       WHERE id = ? AND code = ?
         AND (? IS NULL OR ? >= used_count + reserved_count)
         ${renaming ? "AND used_count = 0 AND reserved_count = 0" : ""}`,
    ).bind(
      ...values,
      id,
      current.code,
      input.usageLimit,
      input.usageLimit,
    ).run();

    if (Number(result.meta?.changes ?? 0) === 0) {
      throw new Error("Voucher diskon berubah bersamaan dengan checkout. Muat ulang lalu coba lagi.");
    }
    return id;
  }

  const historicalCode = await db.prepare(
    "SELECT 1 AS found FROM promotion_reservations WHERE voucher_code = ? LIMIT 1",
  ).bind(normalizedCode).first();
  if (historicalCode) {
    throw new Error("Kode voucher pernah dipakai oleh transaksi lama. Gunakan kode lain agar riwayat promo tetap konsisten.");
  }

  const row = await db.prepare(
    "INSERT INTO discount_vouchers (code, name, description, discount_type, discount_value, min_purchase, max_discount, usage_limit, starts_at, ends_at, is_active) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?) RETURNING id",
  ).bind(...values).first();
  if (!row) throw new Error("Voucher diskon gagal disimpan.");
  return row.id;
}

export async function saveFlashSaleMutation(db, input, id) {
  const packageRow = await db.prepare(
    "SELECT pp.price FROM products p JOIN product_packages pp ON pp.product_id = p.id WHERE p.slug = ? AND pp.sku = ?",
  ).bind(input.productSlug, input.packageSku).first();
  if (!packageRow) throw new Error("Produk atau nominal flash sale tidak ditemukan.");
  if (input.salePrice >= packageRow.price) {
    throw new Error("Harga flash sale harus lebih rendah dari harga normal.");
  }

  const values = [
    input.productSlug,
    input.packageSku,
    input.salePrice,
    input.badge,
    input.startsAt,
    input.endsAt,
    input.stockLimit,
    input.isActive ? 1 : 0,
  ];

  if (id) {
    const result = await db.prepare(
      `UPDATE flash_sales
       SET product_slug = ?, package_sku = ?, sale_price = ?, badge = ?, starts_at = ?,
           ends_at = ?, stock_limit = ?, is_active = ?, updated_at = CURRENT_TIMESTAMP
       WHERE id = ?
         AND ((product_slug = ? AND package_sku = ?) OR (reserved_count = 0 AND sold_count = 0))
         AND (? IS NULL OR ? >= sold_count + reserved_count)`,
    ).bind(
      ...values,
      id,
      input.productSlug,
      input.packageSku,
      input.stockLimit,
      input.stockLimit,
    ).run();

    if (Number(result.meta?.changes ?? 0) === 0) {
      const current = await db.prepare(
        "SELECT product_slug, package_sku, sold_count, reserved_count FROM flash_sales WHERE id = ? LIMIT 1",
      ).bind(id).first();
      if (!current) throw new Error("Flash sale tidak ditemukan.");

      if (
        (current.reserved_count > 0 || current.sold_count > 0) &&
        (current.product_slug !== input.productSlug || current.package_sku !== input.packageSku)
      ) {
        throw new Error("Produk/nominal flash sale tidak dapat diganti setelah promo pernah digunakan atau masih memiliki reservasi aktif.");
      }
      if (input.stockLimit !== null && input.stockLimit < current.sold_count + current.reserved_count) {
        throw new Error("Batas stok tidak boleh lebih kecil dari terjual + reservasi aktif.");
      }
      throw new Error("Flash sale berubah bersamaan dengan checkout. Muat ulang lalu coba lagi.");
    }
    return id;
  }

  const row = await db.prepare(
    "INSERT INTO flash_sales (product_slug, package_sku, sale_price, badge, starts_at, ends_at, stock_limit, is_active) VALUES (?, ?, ?, ?, ?, ?, ?, ?) RETURNING id",
  ).bind(...values).first();
  if (!row) throw new Error("Flash sale gagal disimpan.");
  return row.id;
}

export async function deletePromotionMutation(db, kind, id) {
  if (kind === "voucher") {
    const current = await db.prepare(
      "SELECT code, used_count, reserved_count FROM discount_vouchers WHERE id = ? LIMIT 1",
    ).bind(id).first();
    if (!current) return;

    if (current.reserved_count > 0) {
      throw new Error("Promo tidak dapat dihapus saat masih memiliki reservasi pembayaran aktif.");
    }
    if (current.used_count > 0) {
      throw new Error("Voucher pernah digunakan. Nonaktifkan voucher agar riwayat transaksi tetap mengacu ke voucher yang sama.");
    }

    const historical = await db.prepare(
      "SELECT 1 AS found FROM promotion_reservations WHERE voucher_code = ? LIMIT 1",
    ).bind(current.code).first();
    if (historical) {
      throw new Error("Voucher memiliki riwayat transaksi. Nonaktifkan voucher agar pembayaran terlambat tetap dapat direkonsiliasi.");
    }

    const deleted = await db.prepare(
      "DELETE FROM discount_vouchers WHERE id = ? AND used_count = 0 AND reserved_count = 0",
    ).bind(id).run();
    if (Number(deleted.meta?.changes ?? 0) > 0) return;

    const latest = await db.prepare(
      "SELECT used_count, reserved_count FROM discount_vouchers WHERE id = ? LIMIT 1",
    ).bind(id).first();
    if (!latest) return;
    if (latest.used_count > 0) {
      throw new Error("Voucher baru saja digunakan. Nonaktifkan voucher agar riwayat transaksi tetap konsisten.");
    }
    if (latest.reserved_count > 0) {
      throw new Error("Voucher baru saja direservasi oleh checkout aktif. Nonaktifkan atau coba lagi setelah transaksi selesai.");
    }
    throw new Error("Voucher berubah bersamaan dengan penghapusan. Muat ulang lalu coba lagi.");
  }

  const current = await db.prepare(
    "SELECT sold_count, reserved_count FROM flash_sales WHERE id = ? LIMIT 1",
  ).bind(id).first();
  if (!current) return;

  if (current.reserved_count > 0) {
    throw new Error("Promo tidak dapat dihapus saat masih memiliki reservasi pembayaran aktif.");
  }
  if (current.sold_count > 0) {
    throw new Error("Flash sale pernah digunakan. Nonaktifkan promo agar riwayat transaksi tetap mengacu ke promo yang sama.");
  }

  const historical = await db.prepare(
    "SELECT 1 AS found FROM promotion_reservations WHERE flash_sale_id = ? LIMIT 1",
  ).bind(id).first();
  if (historical) {
    throw new Error("Flash sale memiliki riwayat transaksi. Nonaktifkan promo agar pembayaran terlambat tetap dapat direkonsiliasi.");
  }

  const deleted = await db.prepare(
    "DELETE FROM flash_sales WHERE id = ? AND sold_count = 0 AND reserved_count = 0",
  ).bind(id).run();
  if (Number(deleted.meta?.changes ?? 0) > 0) return;

  const latest = await db.prepare(
    "SELECT sold_count, reserved_count FROM flash_sales WHERE id = ? LIMIT 1",
  ).bind(id).first();
  if (!latest) return;
  if (latest.sold_count > 0) {
    throw new Error("Flash sale baru saja digunakan. Nonaktifkan promo agar riwayat transaksi tetap konsisten.");
  }
  if (latest.reserved_count > 0) {
    throw new Error("Flash sale baru saja direservasi oleh checkout aktif. Nonaktifkan atau coba lagi setelah transaksi selesai.");
  }
  throw new Error("Flash sale berubah bersamaan dengan penghapusan. Muat ulang lalu coba lagi.");
}
