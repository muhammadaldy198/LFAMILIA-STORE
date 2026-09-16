CREATE TABLE IF NOT EXISTS `digiflazz_pricelist_sync_state` (
  `id` integer PRIMARY KEY CHECK (`id` = 1),
  `lock_token` text,
  `locked_until` text,
  `last_started_at` text,
  `last_success_at` text
);
--> statement-breakpoint
INSERT OR IGNORE INTO `digiflazz_pricelist_sync_state` (`id`) VALUES (1);
