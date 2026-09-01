CREATE TABLE IF NOT EXISTS `payment_channels` (
  `id` integer PRIMARY KEY AUTOINCREMENT NOT NULL,
  `method` text NOT NULL,
  `channel` text NOT NULL,
  `name` text NOT NULL,
  `description` text DEFAULT '' NOT NULL,
  `image_url` text,
  `is_active` integer DEFAULT true NOT NULL,
  `sort_order` integer DEFAULT 0 NOT NULL,
  `created_at` text DEFAULT CURRENT_TIMESTAMP NOT NULL,
  `updated_at` text DEFAULT CURRENT_TIMESTAMP NOT NULL
);
CREATE UNIQUE INDEX IF NOT EXISTS `payment_channels_method_channel_unique` ON `payment_channels` (`method`, `channel`);
CREATE INDEX IF NOT EXISTS `payment_channels_active_sort_idx` ON `payment_channels` (`is_active`, `sort_order`);
