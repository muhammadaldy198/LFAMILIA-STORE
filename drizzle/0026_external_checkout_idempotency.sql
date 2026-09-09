ALTER TABLE `orders` ADD `external_checkout_key` text;
--> statement-breakpoint
CREATE UNIQUE INDEX `orders_external_checkout_key_unique` ON `orders` (`external_checkout_key`) WHERE `external_checkout_key` IS NOT NULL;
