ALTER TABLE `products` ADD `image_url` text;
--> statement-breakpoint
ALTER TABLE `products` ADD `manual_open_time` text;
--> statement-breakpoint
ALTER TABLE `products` ADD `manual_close_time` text;
--> statement-breakpoint
ALTER TABLE `products` ADD `manual_timezone` text DEFAULT 'Asia/Jakarta' NOT NULL;
--> statement-breakpoint
ALTER TABLE `orders` ADD `base_subtotal` integer DEFAULT 0 NOT NULL;
--> statement-breakpoint
ALTER TABLE `orders` ADD `discount_amount` integer DEFAULT 0 NOT NULL;
--> statement-breakpoint
ALTER TABLE `orders` ADD `voucher_code` text;
--> statement-breakpoint
ALTER TABLE `orders` ADD `flash_sale_id` integer;
--> statement-breakpoint
UPDATE `orders` SET `base_subtotal` = `subtotal` WHERE `base_subtotal` = 0;
--> statement-breakpoint
CREATE TABLE `store_settings` (
  `id` integer PRIMARY KEY NOT NULL,
  `store_name` text NOT NULL,
  `store_short_name` text NOT NULL,
  `tagline` text NOT NULL,
  `logo_url` text,
  `announcement` text,
  `banner_enabled` integer DEFAULT true NOT NULL,
  `banner_eyebrow` text NOT NULL,
  `banner_title` text NOT NULL,
  `banner_highlight` text NOT NULL,
  `banner_description` text NOT NULL,
  `banner_image_url` text,
  `banner_cta_label` text NOT NULL,
  `banner_cta_href` text NOT NULL,
  `support_whatsapp` text,
  `support_email` text,
  `instagram_url` text,
  `support_hours` text NOT NULL,
  `updated_at` text DEFAULT CURRENT_TIMESTAMP NOT NULL
);
--> statement-breakpoint
INSERT INTO `store_settings` (`id`, `store_name`, `store_short_name`, `tagline`, `announcement`, `banner_enabled`, `banner_eyebrow`, `banner_title`, `banner_highlight`, `banner_description`, `banner_cta_label`, `banner_cta_href`, `support_hours`)
VALUES (1, 'LFAMILIA STORE', 'LF', 'Top up favoritmu, sat set tanpa ribet.', 'Pemesanan tersedia 24 jam', 1, 'Top up & voucher digital', 'Top up favoritmu,', 'sat set tanpa ribet.', 'Game, voucher, promo, dan kalkulator dalam satu website LFAMILIA yang nyaman digunakan kapan saja.', 'Top up sekarang', '#produk', 'Setiap hari, 09.00–23.00 WIB');
--> statement-breakpoint
CREATE TABLE `product_notices` (
  `id` integer PRIMARY KEY AUTOINCREMENT NOT NULL,
  `product_id` integer NOT NULL,
  `title` text NOT NULL,
  `body` text NOT NULL,
  `is_active` integer DEFAULT true NOT NULL,
  `sort_order` integer DEFAULT 0 NOT NULL,
  `created_at` text DEFAULT CURRENT_TIMESTAMP NOT NULL,
  `updated_at` text DEFAULT CURRENT_TIMESTAMP NOT NULL,
  FOREIGN KEY (`product_id`) REFERENCES `products`(`id`) ON UPDATE no action ON DELETE cascade
);
--> statement-breakpoint
CREATE INDEX `product_notices_product_active_sort_idx` ON `product_notices` (`product_id`, `is_active`, `sort_order`);
--> statement-breakpoint
CREATE TABLE `product_categories` (
  `id` integer PRIMARY KEY AUTOINCREMENT NOT NULL,
  `slug` text NOT NULL,
  `name` text NOT NULL,
  `icon` text DEFAULT 'grid' NOT NULL,
  `is_active` integer DEFAULT true NOT NULL,
  `sort_order` integer DEFAULT 0 NOT NULL,
  `created_at` text DEFAULT CURRENT_TIMESTAMP NOT NULL,
  `updated_at` text DEFAULT CURRENT_TIMESTAMP NOT NULL
);
--> statement-breakpoint
CREATE UNIQUE INDEX `product_categories_slug_unique` ON `product_categories` (`slug`);
--> statement-breakpoint
CREATE INDEX `product_categories_active_sort_idx` ON `product_categories` (`is_active`, `sort_order`);
--> statement-breakpoint
INSERT INTO `product_categories` (`slug`, `name`, `icon`, `sort_order`) VALUES
  ('game', 'Top Up Game', 'gamepad', 0),
  ('voucher', 'Voucher & Gift Card', 'ticket', 1),
  ('entertainment', 'Entertainment', 'play', 2),
  ('pulsa', 'Pulsa & Data', 'smartphone', 3);
--> statement-breakpoint
CREATE TABLE `discount_vouchers` (
  `id` integer PRIMARY KEY AUTOINCREMENT NOT NULL,
  `code` text NOT NULL,
  `name` text NOT NULL,
  `description` text DEFAULT '' NOT NULL,
  `discount_type` text NOT NULL,
  `discount_value` integer NOT NULL,
  `min_purchase` integer DEFAULT 0 NOT NULL,
  `max_discount` integer,
  `usage_limit` integer,
  `used_count` integer DEFAULT 0 NOT NULL,
  `starts_at` text NOT NULL,
  `ends_at` text NOT NULL,
  `is_active` integer DEFAULT true NOT NULL,
  `created_at` text DEFAULT CURRENT_TIMESTAMP NOT NULL,
  `updated_at` text DEFAULT CURRENT_TIMESTAMP NOT NULL
);
--> statement-breakpoint
CREATE UNIQUE INDEX `discount_vouchers_code_unique` ON `discount_vouchers` (`code`);
--> statement-breakpoint
CREATE INDEX `discount_vouchers_active_period_idx` ON `discount_vouchers` (`is_active`, `starts_at`, `ends_at`);
--> statement-breakpoint
CREATE TABLE `flash_sales` (
  `id` integer PRIMARY KEY AUTOINCREMENT NOT NULL,
  `product_slug` text NOT NULL,
  `package_sku` text NOT NULL,
  `sale_price` integer NOT NULL,
  `badge` text DEFAULT 'Flash Sale' NOT NULL,
  `starts_at` text NOT NULL,
  `ends_at` text NOT NULL,
  `stock_limit` integer,
  `sold_count` integer DEFAULT 0 NOT NULL,
  `is_active` integer DEFAULT true NOT NULL,
  `created_at` text DEFAULT CURRENT_TIMESTAMP NOT NULL,
  `updated_at` text DEFAULT CURRENT_TIMESTAMP NOT NULL
);
--> statement-breakpoint
CREATE INDEX `flash_sales_product_package_idx` ON `flash_sales` (`product_slug`, `package_sku`);
--> statement-breakpoint
CREATE INDEX `flash_sales_active_period_idx` ON `flash_sales` (`is_active`, `starts_at`, `ends_at`);
--> statement-breakpoint
CREATE TABLE `admin_users` (
  `id` integer PRIMARY KEY AUTOINCREMENT NOT NULL,
  `email` text NOT NULL,
  `name` text NOT NULL,
  `role` text NOT NULL,
  `is_active` integer DEFAULT true NOT NULL,
  `created_at` text DEFAULT CURRENT_TIMESTAMP NOT NULL,
  `updated_at` text DEFAULT CURRENT_TIMESTAMP NOT NULL
);
--> statement-breakpoint
CREATE UNIQUE INDEX `admin_users_email_unique` ON `admin_users` (`email`);
--> statement-breakpoint
CREATE INDEX `admin_users_role_active_idx` ON `admin_users` (`role`, `is_active`);
--> statement-breakpoint
INSERT INTO `admin_users` (`email`, `name`, `role`, `is_active`)
VALUES ('muhammadaldy198@gmail.com', 'Pemilik LFAMILIA', 'owner', 1);
--> statement-breakpoint
CREATE TABLE `faq_entries` (
  `id` integer PRIMARY KEY AUTOINCREMENT NOT NULL,
  `question` text NOT NULL,
  `answer` text NOT NULL,
  `is_active` integer DEFAULT true NOT NULL,
  `sort_order` integer DEFAULT 0 NOT NULL,
  `created_at` text DEFAULT CURRENT_TIMESTAMP NOT NULL,
  `updated_at` text DEFAULT CURRENT_TIMESTAMP NOT NULL
);
--> statement-breakpoint
CREATE INDEX `faq_entries_active_sort_idx` ON `faq_entries` (`is_active`, `sort_order`);
--> statement-breakpoint
INSERT INTO `faq_entries` (`question`, `answer`, `sort_order`) VALUES
  ('Bagaimana cara melakukan top up?', 'Pilih produk, isi data akun, pilih nominal, lalu selesaikan pembayaran. Status pesanan dapat dipantau menggunakan nomor invoice.', 0),
  ('Berapa lama pesanan diproses?', 'Pesanan otomatis diproses setelah pembayaran terverifikasi. Waktu penyelesaian dapat berbeda ketika publisher atau pemasok sedang mengalami gangguan.', 1),
  ('Metode pembayaran apa yang tersedia?', 'Virtual Account bank, dompet digital, dan QRIS tersedia melalui iPaymu sesuai channel yang sedang aktif.', 2),
  ('Bagaimana jika saya salah memasukkan User ID?', 'Periksa kembali data tujuan sebelum membayar. Produk digital yang sudah berhasil dikirim umumnya tidak dapat dibatalkan.', 3);
