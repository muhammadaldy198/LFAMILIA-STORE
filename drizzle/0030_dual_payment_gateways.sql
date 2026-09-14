ALTER TABLE `payment_channels` ADD COLUMN `gateway` text NOT NULL DEFAULT 'doku';
--> statement-breakpoint
ALTER TABLE `payment_channels` ADD COLUMN `gateway_config_json` text NOT NULL DEFAULT '{}';
--> statement-breakpoint
UPDATE `payment_channels` SET `gateway` = 'midtrans' WHERE `method` = 'va';
--> statement-breakpoint
UPDATE `payment_channels` SET `gateway` = 'doku' WHERE `method` IN ('ewallet', 'qris');
--> statement-breakpoint
CREATE TABLE IF NOT EXISTS `payment_gateway_settings` (
  `gateway` text PRIMARY KEY NOT NULL,
  `is_active` integer NOT NULL DEFAULT 0,
  `created_at` text NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` text NOT NULL DEFAULT CURRENT_TIMESTAMP
);
--> statement-breakpoint
INSERT OR IGNORE INTO `payment_gateway_settings` (`gateway`, `is_active`) VALUES ('doku', 0);
--> statement-breakpoint
INSERT OR IGNORE INTO `payment_gateway_settings` (`gateway`, `is_active`) VALUES ('midtrans', 0);
--> statement-breakpoint
ALTER TABLE `orders` ADD COLUMN `payment_gateway` text;
--> statement-breakpoint
ALTER TABLE `orders` ADD COLUMN `payment_gateway_environment` text;
--> statement-breakpoint
ALTER TABLE `orders` ADD COLUMN `gateway_request_id` text;
--> statement-breakpoint
ALTER TABLE `orders` ADD COLUMN `gateway_reference_no` text;
--> statement-breakpoint
ALTER TABLE `orders` ADD COLUMN `gateway_payment_no` text;
--> statement-breakpoint
ALTER TABLE `orders` ADD COLUMN `gateway_qr_content` text;
--> statement-breakpoint
ALTER TABLE `orders` ADD COLUMN `gateway_payment_url` text;
--> statement-breakpoint
ALTER TABLE `orders` ADD COLUMN `gateway_expired_at` text;
--> statement-breakpoint
ALTER TABLE `orders` ADD COLUMN `gateway_status_checked_at` text;
--> statement-breakpoint
UPDATE `orders`
SET `payment_gateway` = 'doku',
    `payment_gateway_environment` = `doku_environment`,
    `gateway_request_id` = `doku_request_id`,
    `gateway_reference_no` = `doku_reference_no`,
    `gateway_payment_no` = `doku_payment_no`,
    `gateway_qr_content` = `doku_qr_content`,
    `gateway_payment_url` = `doku_payment_url`,
    `gateway_expired_at` = `doku_expired_at`,
    `gateway_status_checked_at` = `doku_status_checked_at`
WHERE `payment_method` <> 'wallet' AND `doku_request_id` IS NOT NULL;
--> statement-breakpoint
CREATE INDEX IF NOT EXISTS `orders_payment_gateway_status_idx` ON `orders` (`payment_gateway`, `payment_status`, `created_at`);
