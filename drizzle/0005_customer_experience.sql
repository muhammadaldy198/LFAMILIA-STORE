CREATE TABLE `customer_users` (
  `id` text PRIMARY KEY NOT NULL,
  `email` text NOT NULL,
  `name` text NOT NULL,
  `phone` text NOT NULL,
  `password_hash` text NOT NULL,
  `password_salt` text NOT NULL,
  `balance` integer DEFAULT 0 NOT NULL,
  `leaderboard_opt_in` integer DEFAULT false NOT NULL,
  `is_active` integer DEFAULT true NOT NULL,
  `last_login_at` text,
  `created_at` text DEFAULT CURRENT_TIMESTAMP NOT NULL,
  `updated_at` text DEFAULT CURRENT_TIMESTAMP NOT NULL
);
--> statement-breakpoint
CREATE UNIQUE INDEX `customer_users_email_unique` ON `customer_users` (`email`);
--> statement-breakpoint
CREATE INDEX `customer_users_leaderboard_idx` ON `customer_users` (`leaderboard_opt_in`, `is_active`);
--> statement-breakpoint
CREATE TABLE `customer_sessions` (
  `id` text PRIMARY KEY NOT NULL,
  `customer_id` text NOT NULL,
  `token_hash` text NOT NULL,
  `expires_at` text NOT NULL,
  `created_at` text DEFAULT CURRENT_TIMESTAMP NOT NULL,
  FOREIGN KEY (`customer_id`) REFERENCES `customer_users`(`id`) ON UPDATE no action ON DELETE cascade
);
--> statement-breakpoint
CREATE UNIQUE INDEX `customer_sessions_token_unique` ON `customer_sessions` (`token_hash`);
--> statement-breakpoint
CREATE INDEX `customer_sessions_customer_expiry_idx` ON `customer_sessions` (`customer_id`, `expires_at`);
--> statement-breakpoint
ALTER TABLE `products` ADD `banner_url` text;
--> statement-breakpoint
ALTER TABLE `orders` ADD `customer_id` text REFERENCES `customer_users`(`id`) ON DELETE set null;
--> statement-breakpoint
CREATE INDEX `orders_customer_created_idx` ON `orders` (`customer_id`, `created_at`);
--> statement-breakpoint
ALTER TABLE `store_settings` ADD `discord_url` text;
--> statement-breakpoint
CREATE TABLE `wallet_settings` (
  `id` integer PRIMARY KEY NOT NULL,
  `is_enabled` integer DEFAULT false NOT NULL,
  `method_name` text DEFAULT 'Transfer Bank' NOT NULL,
  `account_name` text DEFAULT '' NOT NULL,
  `account_number` text DEFAULT '' NOT NULL,
  `instructions` text DEFAULT '' NOT NULL,
  `min_topup` integer DEFAULT 10000 NOT NULL,
  `updated_at` text DEFAULT CURRENT_TIMESTAMP NOT NULL
);
--> statement-breakpoint
CREATE TABLE `wallet_topups` (
  `id` text PRIMARY KEY NOT NULL,
  `customer_id` text NOT NULL,
  `amount` integer NOT NULL,
  `sender_name` text NOT NULL,
  `payment_method` text NOT NULL,
  `proof_url` text NOT NULL,
  `status` text DEFAULT 'pending' NOT NULL,
  `admin_notes` text,
  `reviewed_by` text,
  `reviewed_at` text,
  `created_at` text DEFAULT CURRENT_TIMESTAMP NOT NULL,
  `updated_at` text DEFAULT CURRENT_TIMESTAMP NOT NULL,
  FOREIGN KEY (`customer_id`) REFERENCES `customer_users`(`id`) ON UPDATE no action ON DELETE cascade
);
--> statement-breakpoint
CREATE INDEX `wallet_topups_customer_created_idx` ON `wallet_topups` (`customer_id`, `created_at`);
--> statement-breakpoint
CREATE INDEX `wallet_topups_status_created_idx` ON `wallet_topups` (`status`, `created_at`);
--> statement-breakpoint
CREATE TABLE `wallet_transactions` (
  `id` text PRIMARY KEY NOT NULL,
  `customer_id` text NOT NULL,
  `direction` text NOT NULL,
  `amount` integer NOT NULL,
  `balance_before` integer NOT NULL,
  `balance_after` integer NOT NULL,
  `reference` text NOT NULL,
  `description` text NOT NULL,
  `created_at` text DEFAULT CURRENT_TIMESTAMP NOT NULL,
  FOREIGN KEY (`customer_id`) REFERENCES `customer_users`(`id`) ON UPDATE no action ON DELETE cascade
);
--> statement-breakpoint
CREATE UNIQUE INDEX `wallet_transactions_reference_unique` ON `wallet_transactions` (`reference`);
--> statement-breakpoint
CREATE INDEX `wallet_transactions_customer_created_idx` ON `wallet_transactions` (`customer_id`, `created_at`);
--> statement-breakpoint
CREATE TABLE `product_reviews` (
  `id` integer PRIMARY KEY AUTOINCREMENT NOT NULL,
  `customer_id` text NOT NULL,
  `product_slug` text NOT NULL,
  `rating` integer NOT NULL,
  `title` text,
  `body` text NOT NULL,
  `is_verified_purchase` integer DEFAULT false NOT NULL,
  `is_visible` integer DEFAULT true NOT NULL,
  `created_at` text DEFAULT CURRENT_TIMESTAMP NOT NULL,
  `updated_at` text DEFAULT CURRENT_TIMESTAMP NOT NULL,
  FOREIGN KEY (`customer_id`) REFERENCES `customer_users`(`id`) ON UPDATE no action ON DELETE cascade
);
--> statement-breakpoint
CREATE UNIQUE INDEX `product_reviews_customer_product_unique` ON `product_reviews` (`customer_id`, `product_slug`);
--> statement-breakpoint
CREATE INDEX `product_reviews_product_visible_idx` ON `product_reviews` (`product_slug`, `is_visible`, `created_at`);
--> statement-breakpoint
CREATE TABLE `home_banners` (
  `id` integer PRIMARY KEY AUTOINCREMENT NOT NULL,
  `title` text NOT NULL,
  `subtitle` text DEFAULT '' NOT NULL,
  `image_url` text NOT NULL,
  `cta_label` text DEFAULT 'Lihat produk' NOT NULL,
  `cta_href` text DEFAULT '#produk' NOT NULL,
  `is_active` integer DEFAULT true NOT NULL,
  `sort_order` integer DEFAULT 0 NOT NULL,
  `created_at` text DEFAULT CURRENT_TIMESTAMP NOT NULL,
  `updated_at` text DEFAULT CURRENT_TIMESTAMP NOT NULL
);
--> statement-breakpoint
CREATE INDEX `home_banners_active_sort_idx` ON `home_banners` (`is_active`, `sort_order`);
--> statement-breakpoint
CREATE TABLE `site_popups` (
  `id` integer PRIMARY KEY AUTOINCREMENT NOT NULL,
  `title` text NOT NULL,
  `body` text NOT NULL,
  `primary_label` text,
  `primary_href` text,
  `secondary_label` text,
  `secondary_href` text,
  `dismiss_days` integer DEFAULT 7 NOT NULL,
  `is_active` integer DEFAULT true NOT NULL,
  `sort_order` integer DEFAULT 0 NOT NULL,
  `created_at` text DEFAULT CURRENT_TIMESTAMP NOT NULL,
  `updated_at` text DEFAULT CURRENT_TIMESTAMP NOT NULL
);
--> statement-breakpoint
CREATE INDEX `site_popups_active_sort_idx` ON `site_popups` (`is_active`, `sort_order`);
--> statement-breakpoint
CREATE TABLE `news_articles` (
  `id` integer PRIMARY KEY AUTOINCREMENT NOT NULL,
  `slug` text NOT NULL,
  `title` text NOT NULL,
  `summary` text DEFAULT '' NOT NULL,
  `body` text NOT NULL,
  `cover_url` text,
  `is_published` integer DEFAULT false NOT NULL,
  `published_at` text,
  `sort_order` integer DEFAULT 0 NOT NULL,
  `created_at` text DEFAULT CURRENT_TIMESTAMP NOT NULL,
  `updated_at` text DEFAULT CURRENT_TIMESTAMP NOT NULL
);
--> statement-breakpoint
CREATE UNIQUE INDEX `news_articles_slug_unique` ON `news_articles` (`slug`);
--> statement-breakpoint
CREATE INDEX `news_articles_published_sort_idx` ON `news_articles` (`is_published`, `published_at`, `sort_order`);
