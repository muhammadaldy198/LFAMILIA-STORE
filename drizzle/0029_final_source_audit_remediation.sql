-- Final source-audit remediation. Apply once through the normal D1 migration workflow.
ALTER TABLE orders ADD COLUMN delivery_mode TEXT;
ALTER TABLE orders ADD COLUMN supplier_cost_snapshot INTEGER;
ALTER TABLE orders ADD COLUMN doku_environment TEXT;
ALTER TABLE discount_vouchers ADD COLUMN reserved_count INTEGER NOT NULL DEFAULT 0;
ALTER TABLE flash_sales ADD COLUMN reserved_count INTEGER NOT NULL DEFAULT 0;
CREATE TABLE IF NOT EXISTS promotion_reservations (
  order_id TEXT PRIMARY KEY, voucher_code TEXT, flash_sale_id INTEGER,
  status TEXT NOT NULL CHECK(status IN ('reserved','consumed','released')),
  expires_at TEXT NOT NULL, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS promotion_reservations_expiry_idx ON promotion_reservations(status, expires_at);
CREATE TRIGGER IF NOT EXISTS promotion_reservation_voucher_guard BEFORE INSERT ON promotion_reservations WHEN NEW.voucher_code IS NOT NULL BEGIN
  SELECT CASE WHEN NOT EXISTS (SELECT 1 FROM discount_vouchers v WHERE v.code = NEW.voucher_code AND v.is_active = 1 AND datetime('now') BETWEEN datetime(v.starts_at) AND datetime(v.ends_at) AND (v.usage_limit IS NULL OR v.used_count + v.reserved_count < v.usage_limit)) THEN RAISE(ABORT, 'Voucher sudah tidak tersedia') END;
END;
CREATE TRIGGER IF NOT EXISTS promotion_reservation_flash_guard BEFORE INSERT ON promotion_reservations WHEN NEW.flash_sale_id IS NOT NULL BEGIN
  SELECT CASE WHEN NOT EXISTS (SELECT 1 FROM flash_sales f WHERE f.id = NEW.flash_sale_id AND f.is_active = 1 AND datetime('now') BETWEEN datetime(f.starts_at) AND datetime(f.ends_at) AND (f.stock_limit IS NULL OR f.sold_count + f.reserved_count < f.stock_limit)) THEN RAISE(ABORT, 'Flash sale sudah tidak tersedia') END;
END;
CREATE TRIGGER IF NOT EXISTS promotion_reservation_insert AFTER INSERT ON promotion_reservations WHEN NEW.status = 'reserved' BEGIN
  UPDATE discount_vouchers SET reserved_count = reserved_count + 1 WHERE code = NEW.voucher_code;
  UPDATE flash_sales SET reserved_count = reserved_count + 1 WHERE id = NEW.flash_sale_id;
END;
CREATE TRIGGER IF NOT EXISTS promotion_reservation_consumed AFTER UPDATE OF status ON promotion_reservations WHEN OLD.status = 'reserved' AND NEW.status = 'consumed' BEGIN
  UPDATE discount_vouchers SET reserved_count = MAX(0, reserved_count - 1), used_count = used_count + 1, updated_at = CURRENT_TIMESTAMP WHERE code = NEW.voucher_code;
  UPDATE flash_sales SET reserved_count = MAX(0, reserved_count - 1), sold_count = sold_count + 1, updated_at = CURRENT_TIMESTAMP WHERE id = NEW.flash_sale_id;
END;
CREATE TRIGGER IF NOT EXISTS promotion_reservation_released AFTER UPDATE OF status ON promotion_reservations WHEN OLD.status = 'reserved' AND NEW.status = 'released' BEGIN
  UPDATE discount_vouchers SET reserved_count = MAX(0, reserved_count - 1), updated_at = CURRENT_TIMESTAMP WHERE code = NEW.voucher_code;
  UPDATE flash_sales SET reserved_count = MAX(0, reserved_count - 1), updated_at = CURRENT_TIMESTAMP WHERE id = NEW.flash_sale_id;
END;
UPDATE orders SET delivery_mode = CASE WHEN fulfillment_type = 'manual' THEN 'manual' WHEN provider_code = 'voucher-stock' THEN 'voucher' ELSE 'direct' END WHERE delivery_mode IS NULL;
UPDATE orders SET supplier_cost_snapshot = (SELECT supplier_price FROM product_packages pp WHERE pp.sku = orders.package_sku AND pp.supplier_price IS NOT NULL ORDER BY pp.id DESC LIMIT 1) WHERE supplier_cost_snapshot IS NULL;
