CREATE TABLE IF NOT EXISTS `digiflazz_pricelist_sync_state` (
  `id` integer PRIMARY KEY CHECK (`id` = 1),
  `lock_token` text,
  `locked_until` text,
  `last_started_at` text,
  `last_success_at` text
);
--> statement-breakpoint
INSERT OR IGNORE INTO `digiflazz_pricelist_sync_state` (`id`) VALUES (1);
--> statement-breakpoint
CREATE TABLE IF NOT EXISTS `digiflazz_runtime_state` (
  `id` integer PRIMARY KEY CHECK (`id` = 1),
  `maintenance_token` text,
  `maintenance_until` text,
  `generation` integer NOT NULL DEFAULT 1,
  `last_changed_at` text
);
--> statement-breakpoint
INSERT OR IGNORE INTO `digiflazz_runtime_state` (`id`) VALUES (1);
--> statement-breakpoint
CREATE TRIGGER IF NOT EXISTS `digiflazz_order_maintenance_guard`
BEFORE INSERT ON `orders`
WHEN NEW.`provider_code` = 'digiflazz'
  AND EXISTS (
    SELECT 1 FROM `digiflazz_runtime_state`
    WHERE `id` = 1
      AND `maintenance_token` IS NOT NULL
      AND `maintenance_until` > CURRENT_TIMESTAMP
  )
BEGIN
  SELECT RAISE(ABORT, 'DIGIFLAZZ_CONFIG_MAINTENANCE');
END;
