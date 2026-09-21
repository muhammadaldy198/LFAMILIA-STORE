INSERT INTO `product_categories` (`slug`, `name`, `icon`, `is_active`, `sort_order`)
SELECT 'pln', 'PLN', 'zap', 1, 4
WHERE NOT EXISTS (
  SELECT 1 FROM `product_categories` WHERE `slug` = 'pln'
);
