CREATE TABLE IF NOT EXISTS `integration_profiles` (
  `id` integer PRIMARY KEY AUTOINCREMENT NOT NULL,
  `provider` text NOT NULL,
  `mode` text NOT NULL,
  `environment` text NOT NULL,
  `encrypted_config` text NOT NULL,
  `created_at` text DEFAULT CURRENT_TIMESTAMP NOT NULL,
  `updated_at` text DEFAULT CURRENT_TIMESTAMP NOT NULL
);
--> statement-breakpoint
CREATE UNIQUE INDEX IF NOT EXISTS `integration_profiles_scope_unique`
  ON `integration_profiles` (`provider`, `mode`, `environment`);
--> statement-breakpoint
CREATE TABLE IF NOT EXISTS `integration_settings` (
  `setting_key` text PRIMARY KEY NOT NULL,
  `value` text NOT NULL,
  `updated_at` text DEFAULT CURRENT_TIMESTAMP NOT NULL
);
