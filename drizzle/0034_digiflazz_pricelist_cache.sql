CREATE TABLE IF NOT EXISTS `digiflazz_pricelist_cache` (
  `buyer_sku_code` text PRIMARY KEY NOT NULL,
  `product_name` text NOT NULL,
  `category` text NOT NULL DEFAULT '',
  `brand` text NOT NULL DEFAULT '',
  `type` text NOT NULL DEFAULT '',
  `seller_name` text NOT NULL DEFAULT '',
  `price` integer NOT NULL,
  `buyer_product_status` integer NOT NULL DEFAULT 1,
  `seller_product_status` integer NOT NULL DEFAULT 1,
  `unlimited_stock` integer NOT NULL DEFAULT 0,
  `stock` integer NOT NULL DEFAULT 0,
  `multi` integer NOT NULL DEFAULT 0,
  `start_cut_off` text NOT NULL DEFAULT '00:00',
  `end_cut_off` text NOT NULL DEFAULT '00:00',
  `description` text NOT NULL DEFAULT '',
  `synced_at` text NOT NULL
);
--> statement-breakpoint
CREATE INDEX IF NOT EXISTS `digiflazz_pricelist_cache_brand_idx`
ON `digiflazz_pricelist_cache` (`brand`, `product_name`);
