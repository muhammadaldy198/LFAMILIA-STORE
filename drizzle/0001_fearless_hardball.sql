CREATE TABLE `order_events` (
	`id` integer PRIMARY KEY AUTOINCREMENT NOT NULL,
	`order_id` text NOT NULL,
	`source` text NOT NULL,
	`event_id` text NOT NULL,
	`status` text NOT NULL,
	`payload_json` text NOT NULL,
	`created_at` text DEFAULT CURRENT_TIMESTAMP NOT NULL,
	FOREIGN KEY (`order_id`) REFERENCES `orders`(`id`) ON UPDATE no action ON DELETE cascade
);
--> statement-breakpoint
CREATE UNIQUE INDEX `order_events_source_event_unique` ON `order_events` (`source`,`event_id`);--> statement-breakpoint
CREATE INDEX `order_events_order_created_idx` ON `order_events` (`order_id`,`created_at`);--> statement-breakpoint
CREATE TABLE `orders` (
	`id` text PRIMARY KEY NOT NULL,
	`reference_id` text NOT NULL,
	`product_slug` text NOT NULL,
	`product_name` text NOT NULL,
	`package_sku` text NOT NULL,
	`package_label` text NOT NULL,
	`provider_code` text,
	`provider_sku` text,
	`fulfillment_type` text NOT NULL,
	`target_template` text NOT NULL,
	`destination` text NOT NULL,
	`server` text,
	`nickname` text,
	`customer_no` text,
	`buyer_name` text NOT NULL,
	`buyer_email` text NOT NULL,
	`buyer_phone` text NOT NULL,
	`customer_notes` text,
	`subtotal` integer NOT NULL,
	`admin_fee` integer DEFAULT 0 NOT NULL,
	`total` integer NOT NULL,
	`payment_method` text NOT NULL,
	`payment_channel` text NOT NULL,
	`payment_status` text DEFAULT 'pending' NOT NULL,
	`fulfillment_status` text DEFAULT 'waiting_payment' NOT NULL,
	`provider_ref_id` text,
	`provider_status` text,
	`provider_message` text,
	`provider_serial_number` text,
	`created_at` text DEFAULT CURRENT_TIMESTAMP NOT NULL,
	`updated_at` text DEFAULT CURRENT_TIMESTAMP NOT NULL
);
--> statement-breakpoint
CREATE UNIQUE INDEX `orders_reference_id_unique` ON `orders` (`reference_id`);--> statement-breakpoint
CREATE UNIQUE INDEX `orders_provider_ref_id_unique` ON `orders` (`provider_code`,`provider_ref_id`);--> statement-breakpoint
CREATE INDEX `orders_payment_fulfillment_idx` ON `orders` (`payment_status`,`fulfillment_status`);--> statement-breakpoint
CREATE INDEX `orders_created_at_idx` ON `orders` (`created_at`);--> statement-breakpoint
ALTER TABLE `product_packages` ADD `provider_code` text;--> statement-breakpoint
ALTER TABLE `product_packages` ADD `provider_sku` text;--> statement-breakpoint
ALTER TABLE `products` ADD `fulfillment_type` text DEFAULT 'automatic' NOT NULL;--> statement-breakpoint
ALTER TABLE `products` ADD `target_template` text DEFAULT '{{destination}}{{server}}' NOT NULL;--> statement-breakpoint
ALTER TABLE `products` ADD `manual_instructions` text;