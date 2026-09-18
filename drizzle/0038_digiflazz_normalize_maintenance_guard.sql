DROP TRIGGER IF EXISTS `digiflazz_order_maintenance_guard`;
--> statement-breakpoint
CREATE TRIGGER `digiflazz_order_maintenance_guard`
BEFORE INSERT ON `orders`
WHEN lower(trim(NEW.`provider_code`)) = 'digiflazz'
  AND EXISTS (
    SELECT 1 FROM `digiflazz_runtime_state`
    WHERE `id` = 1
      AND `maintenance_token` IS NOT NULL
      AND `maintenance_until` > CURRENT_TIMESTAMP
  )
BEGIN
  SELECT RAISE(ABORT, 'DIGIFLAZZ_CONFIG_MAINTENANCE');
END;
