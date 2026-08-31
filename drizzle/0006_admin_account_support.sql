CREATE TABLE IF NOT EXISTS `customer_users` (
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
CREATE UNIQUE INDEX IF NOT EXISTS `customer_users_email_unique`
ON `customer_users` (`email`);
--> statement-breakpoint
CREATE INDEX IF NOT EXISTS `customer_users_leaderboard_idx`
ON `customer_users` (`leaderboard_opt_in`, `is_active`);
--> statement-breakpoint
CREATE TABLE IF NOT EXISTS `customer_sessions` (
  `id` text PRIMARY KEY NOT NULL,
  `customer_id` text NOT NULL,
  `token_hash` text NOT NULL,
  `expires_at` text NOT NULL,
  `created_at` text DEFAULT CURRENT_TIMESTAMP NOT NULL,
  FOREIGN KEY (`customer_id`) REFERENCES `customer_users`(`id`) ON UPDATE no action ON DELETE cascade
);
--> statement-breakpoint
CREATE UNIQUE INDEX IF NOT EXISTS `customer_sessions_token_unique`
ON `customer_sessions` (`token_hash`);
--> statement-breakpoint
CREATE INDEX IF NOT EXISTS `customer_sessions_customer_expiry_idx`
ON `customer_sessions` (`customer_id`, `expires_at`);
