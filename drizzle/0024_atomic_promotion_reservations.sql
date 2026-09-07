ALTER TABLE `orders` ADD `promotion_reservation_status` text DEFAULT 'legacy' NOT NULL;
--> statement-breakpoint
ALTER TABLE `orders` ADD `voucher_id` integer;
--> statement-breakpoint
ALTER TABLE `orders` ADD `promotion_reserved_until` text;
