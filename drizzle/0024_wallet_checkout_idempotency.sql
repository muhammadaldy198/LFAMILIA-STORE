ALTER TABLE `orders` ADD `wallet_checkout_key` text;
--> statement-breakpoint
CREATE UNIQUE INDEX `orders_wallet_checkout_key_unique` ON `orders` (`customer_id`, `wallet_checkout_key`);
