ALTER TABLE `product_packages` ADD COLUMN `provider_max_price` integer;
--> statement-breakpoint
ALTER TABLE `orders` ADD COLUMN `provider_max_price_snapshot` integer;
--> statement-breakpoint
UPDATE `product_packages`
SET `provider_max_price` = `supplier_price`
WHERE `provider_code` = 'digiflazz'
  AND `provider_max_price` IS NULL
  AND `supplier_price` IS NOT NULL;
--> statement-breakpoint
UPDATE `orders`
SET `provider_max_price_snapshot` = (
  SELECT pp.`provider_max_price`
  FROM `product_packages` pp
  JOIN `products` p ON p.`id` = pp.`product_id`
  WHERE p.`slug` = `orders`.`product_slug`
    AND pp.`sku` = `orders`.`package_sku`
  LIMIT 1
)
WHERE `provider_max_price_snapshot` IS NULL;