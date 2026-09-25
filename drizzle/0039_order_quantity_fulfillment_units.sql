ALTER TABLE `orders` ADD COLUMN `quantity` integer NOT NULL DEFAULT 1;
--> statement-breakpoint
CREATE TABLE IF NOT EXISTS `order_fulfillment_units` (
  `id` integer PRIMARY KEY AUTOINCREMENT NOT NULL,
  `order_id` text NOT NULL,
  `unit_index` integer NOT NULL,
  `provider_ref_id` text NOT NULL,
  `provider_status` text NOT NULL DEFAULT 'waiting',
  `provider_message` text,
  `provider_serial_number` text,
  `attempts` integer NOT NULL DEFAULT 0,
  `created_at` text NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` text NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`order_id`) REFERENCES `orders`(`id`) ON UPDATE no action ON DELETE cascade
);
--> statement-breakpoint
CREATE UNIQUE INDEX IF NOT EXISTS `order_fulfillment_units_order_index_unique` ON `order_fulfillment_units` (`order_id`,`unit_index`);
--> statement-breakpoint
CREATE UNIQUE INDEX IF NOT EXISTS `order_fulfillment_units_provider_ref_unique` ON `order_fulfillment_units` (`provider_ref_id`);
--> statement-breakpoint
CREATE INDEX IF NOT EXISTS `order_fulfillment_units_order_status_idx` ON `order_fulfillment_units` (`order_id`,`provider_status`);
