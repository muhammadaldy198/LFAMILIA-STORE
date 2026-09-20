-- Final pre-production routing cleanup.
-- The store has not launched DOKU Direct in production. Historical Direct
-- sandbox/dev sessions are retired; they are never relabeled as Checkout.
-- DOKU credential profiles are migrated/sanitized by payment-mode-config.ts
-- because encrypted_config cannot be safely field-filtered in SQL.

UPDATE promotion_reservations
SET status = 'released',
    updated_at = CURRENT_TIMESTAMP
WHERE status = 'reserved'
  AND order_id IN (
    SELECT id
    FROM orders
    WHERE payment_gateway = 'doku'
      AND payment_gateway_mode = 'direct'
      AND payment_status = 'pending'
  );
--> statement-breakpoint

UPDATE orders
SET payment_status = 'expired',
    provider_message = COALESCE(provider_message, 'Pembayaran sandbox lama dibatalkan saat migrasi DOKU Checkout.'),
    gateway_request_id = NULL,
    gateway_reference_no = NULL,
    gateway_payment_no = NULL,
    gateway_qr_content = NULL,
    gateway_payment_url = NULL,
    gateway_expired_at = CURRENT_TIMESTAMP,
    doku_request_id = NULL,
    doku_token_id = NULL,
    doku_reference_no = NULL,
    doku_payment_no = NULL,
    doku_qr_content = NULL,
    doku_payment_name = NULL,
    doku_payment_url = NULL,
    doku_expired_at = CURRENT_TIMESTAMP,
    updated_at = CURRENT_TIMESTAMP
WHERE payment_gateway = 'doku'
  AND payment_gateway_mode = 'direct'
  AND payment_status = 'pending';
--> statement-breakpoint

UPDATE wallet_topups
SET status = 'rejected',
    admin_notes = COALESCE(admin_notes, 'Pembayaran sandbox lama dibatalkan saat migrasi DOKU Checkout.'),
    gateway_request_id = NULL,
    gateway_reference_no = NULL,
    gateway_payment_no = NULL,
    gateway_qr_content = NULL,
    gateway_payment_name = NULL,
    gateway_payment_url = NULL,
    gateway_expired_at = CURRENT_TIMESTAMP,
    doku_request_id = NULL,
    doku_token_id = NULL,
    doku_reference_no = NULL,
    doku_payment_no = NULL,
    doku_qr_content = NULL,
    doku_payment_name = NULL,
    doku_payment_url = NULL,
    doku_expired_at = CURRENT_TIMESTAMP,
    updated_at = CURRENT_TIMESTAMP
WHERE payment_gateway = 'doku'
  AND payment_gateway_mode = 'direct'
  AND status = 'pending';
--> statement-breakpoint

UPDATE orders
SET payment_gateway_mode = NULL
WHERE payment_gateway = 'doku'
  AND payment_gateway_mode = 'direct';
--> statement-breakpoint

UPDATE wallet_topups
SET payment_gateway_mode = NULL
WHERE payment_gateway = 'doku'
  AND payment_gateway_mode = 'direct';
--> statement-breakpoint

UPDATE orders
SET payment_gateway_mode = 'snap'
WHERE payment_gateway = 'midtrans'
  AND payment_gateway_mode = 'bisnap';
--> statement-breakpoint

UPDATE wallet_topups
SET payment_gateway_mode = 'snap'
WHERE payment_gateway = 'midtrans'
  AND payment_gateway_mode = 'bisnap';
