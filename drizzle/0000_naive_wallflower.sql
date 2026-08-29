CREATE TABLE `product_packages` (
	`id` integer PRIMARY KEY AUTOINCREMENT NOT NULL,
	`product_id` integer NOT NULL,
	`sku` text NOT NULL,
	`label` text NOT NULL,
	`price` integer NOT NULL,
	`note` text,
	`is_active` integer DEFAULT true NOT NULL,
	`sort_order` integer DEFAULT 0 NOT NULL,
	`created_at` text DEFAULT CURRENT_TIMESTAMP NOT NULL,
	`updated_at` text DEFAULT CURRENT_TIMESTAMP NOT NULL,
	FOREIGN KEY (`product_id`) REFERENCES `products`(`id`) ON UPDATE no action ON DELETE cascade
);
--> statement-breakpoint
CREATE UNIQUE INDEX `product_packages_sku_unique` ON `product_packages` (`sku`);--> statement-breakpoint
CREATE INDEX `product_packages_product_sort_idx` ON `product_packages` (`product_id`,`is_active`,`sort_order`);--> statement-breakpoint
CREATE TABLE `products` (
	`id` integer PRIMARY KEY AUTOINCREMENT NOT NULL,
	`slug` text NOT NULL,
	`name` text NOT NULL,
	`publisher` text DEFAULT '' NOT NULL,
	`category` text NOT NULL,
	`initials` text NOT NULL,
	`accent` text NOT NULL,
	`input_label` text NOT NULL,
	`input_placeholder` text NOT NULL,
	`needs_server` integer DEFAULT false NOT NULL,
	`popular` integer DEFAULT false NOT NULL,
	`instant` integer DEFAULT false NOT NULL,
	`is_active` integer DEFAULT true NOT NULL,
	`sort_order` integer DEFAULT 0 NOT NULL,
	`created_at` text DEFAULT CURRENT_TIMESTAMP NOT NULL,
	`updated_at` text DEFAULT CURRENT_TIMESTAMP NOT NULL
);
--> statement-breakpoint
CREATE UNIQUE INDEX `products_slug_unique` ON `products` (`slug`);--> statement-breakpoint
CREATE INDEX `products_active_sort_idx` ON `products` (`is_active`,`sort_order`);