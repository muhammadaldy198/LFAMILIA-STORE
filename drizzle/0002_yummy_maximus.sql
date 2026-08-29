CREATE TABLE `voucher_codes` (
	`id` integer PRIMARY KEY AUTOINCREMENT NOT NULL,
	`stock_key` text NOT NULL,
	`code_ciphertext` text NOT NULL,
	`code_iv` text NOT NULL,
	`code_tag` text NOT NULL,
	`code_hash` text NOT NULL,
	`status` text DEFAULT 'available' NOT NULL,
	`order_id` text,
	`reserved_at` text,
	`delivered_at` text,
	`created_at` text DEFAULT CURRENT_TIMESTAMP NOT NULL,
	FOREIGN KEY (`order_id`) REFERENCES `orders`(`id`) ON UPDATE no action ON DELETE set null
);
--> statement-breakpoint
CREATE UNIQUE INDEX `voucher_codes_hash_unique` ON `voucher_codes` (`code_hash`);--> statement-breakpoint
CREATE UNIQUE INDEX `voucher_codes_order_unique` ON `voucher_codes` (`order_id`);--> statement-breakpoint
CREATE INDEX `voucher_codes_stock_status_idx` ON `voucher_codes` (`stock_key`,`status`,`id`);--> statement-breakpoint
CREATE TABLE `voucher_deliveries` (
	`id` integer PRIMARY KEY AUTOINCREMENT NOT NULL,
	`order_id` text NOT NULL,
	`voucher_code_id` integer NOT NULL,
	`channel` text NOT NULL,
	`status` text DEFAULT 'pending' NOT NULL,
	`provider_id` text,
	`provider_message` text,
	`attempts` integer DEFAULT 0 NOT NULL,
	`last_attempt_at` text,
	`created_at` text DEFAULT CURRENT_TIMESTAMP NOT NULL,
	`updated_at` text DEFAULT CURRENT_TIMESTAMP NOT NULL,
	FOREIGN KEY (`order_id`) REFERENCES `orders`(`id`) ON UPDATE no action ON DELETE cascade,
	FOREIGN KEY (`voucher_code_id`) REFERENCES `voucher_codes`(`id`) ON UPDATE no action ON DELETE cascade
);
--> statement-breakpoint
CREATE UNIQUE INDEX `voucher_deliveries_order_channel_unique` ON `voucher_deliveries` (`order_id`,`channel`);--> statement-breakpoint
CREATE INDEX `voucher_deliveries_status_updated_idx` ON `voucher_deliveries` (`status`,`updated_at`);