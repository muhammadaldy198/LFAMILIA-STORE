ALTER TABLE `orders` ADD COLUMN `payment_gateway_mode` text;
--> statement-breakpoint
ALTER TABLE `wallet_topups` ADD COLUMN `payment_gateway` text;
--> statement-breakpoint
ALTER TABLE `wallet_topups` ADD COLUMN `payment_gateway_mode` text;
--> statement-breakpoint
ALTER TABLE `wallet_topups` ADD COLUMN `gateway_environment` text;
--> statement-breakpoint
ALTER TABLE `wallet_topups` ADD COLUMN `gateway_request_id` text;
--> statement-breakpoint
ALTER TABLE `wallet_topups` ADD COLUMN `gateway_reference_no` text;
--> statement-breakpoint
ALTER TABLE `wallet_topups` ADD COLUMN `gateway_payment_no` text;
--> statement-breakpoint
ALTER TABLE `wallet_topups` ADD COLUMN `gateway_qr_content` text;
--> statement-breakpoint
ALTER TABLE `wallet_topups` ADD COLUMN `gateway_payment_name` text;
--> statement-breakpoint
ALTER TABLE `wallet_topups` ADD COLUMN `gateway_payment_url` text;
--> statement-breakpoint
ALTER TABLE `wallet_topups` ADD COLUMN `gateway_expired_at` text;
--> statement-breakpoint
UPDATE `orders`
SET `payment_gateway_mode` = CASE
  WHEN `payment_gateway` = 'midtrans' THEN 'bisnap'
  WHEN `payment_gateway` = 'doku' THEN 'checkout'
  ELSE `payment_gateway_mode`
END
WHERE `payment_gateway_mode` IS NULL AND `payment_gateway` IN ('doku', 'midtrans');
--> statement-breakpoint
UPDATE `wallet_topups`
SET `payment_gateway` = 'doku',
    `payment_gateway_mode` = 'checkout',
    `gateway_environment` = `doku_environment`,
    `gateway_request_id` = `doku_request_id`,
    `gateway_reference_no` = `doku_reference_no`,
    `gateway_payment_no` = `doku_payment_no`,
    `gateway_qr_content` = `doku_qr_content`,
    `gateway_payment_name` = `doku_payment_name`,
    `gateway_payment_url` = `doku_payment_url`,
    `gateway_expired_at` = `doku_expired_at`
WHERE `source` = 'doku';
--> statement-breakpoint
CREATE INDEX IF NOT EXISTS `wallet_topups_gateway_status_idx`
ON `wallet_topups` (`payment_gateway`, `status`, `created_at`);
