-- One-time pre-launch catalog repopulation requested by the store owner.
-- This migration only inserts missing rows. It does not add any runtime restore,
-- seed endpoint, admin restore button, or automatic catalog recovery behavior.

INSERT OR IGNORE INTO products (
  slug, name, publisher, category, image_url, banner_url, description, initials, accent,
  input_label, input_placeholder, input_fields_json, needs_server, popular, instant,
  fulfillment_type, target_template, manual_instructions, manual_open_time, manual_close_time,
  manual_timezone, is_active, sort_order
) VALUES (
  'mobile-legends', 'Mobile Legends', 'Moonton', 'game', '/products/mobile-legends-card.webp', '/products/mobile-legends-banner.webp', NULL, 'ML', 'from-[#5577ff] via-[#314fc0] to-[#16276c]',
  'User ID', 'Contoh: 123456789', '[{"id":"account-id","label":"User ID","placeholder":"Contoh: 123456789","required":true},{"id":"server-zone","label":"Server / Zone ID","placeholder":"Contoh: 1234","required":true}]', 1, 1, 1,
  'automatic', '{{destination}}{{server}}', NULL, NULL, NULL,
  'Asia/Jakarta', 1, 0
);
--> statement-breakpoint
INSERT OR IGNORE INTO product_packages (
  product_id, sku, label, price, note, provider_code, provider_sku, supplier_price,
  pricing_mode, margin_type, margin_value, is_active, sort_order
)
SELECT id, 'ml-5', '5 Diamonds', 2500, NULL, NULL, NULL, NULL,
       'manual', 'fixed', 0, 1, 0
FROM products
WHERE slug = 'mobile-legends';
--> statement-breakpoint
INSERT OR IGNORE INTO product_packages (
  product_id, sku, label, price, note, provider_code, provider_sku, supplier_price,
  pricing_mode, margin_type, margin_value, is_active, sort_order
)
SELECT id, 'ml-12', '12 Diamonds', 4500, NULL, NULL, NULL, NULL,
       'manual', 'fixed', 0, 1, 1
FROM products
WHERE slug = 'mobile-legends';
--> statement-breakpoint
INSERT OR IGNORE INTO product_packages (
  product_id, sku, label, price, note, provider_code, provider_sku, supplier_price,
  pricing_mode, margin_type, margin_value, is_active, sort_order
)
SELECT id, 'ml-28', '28 Diamonds', 9000, NULL, NULL, NULL, NULL,
       'manual', 'fixed', 0, 1, 2
FROM products
WHERE slug = 'mobile-legends';
--> statement-breakpoint
INSERT OR IGNORE INTO product_packages (
  product_id, sku, label, price, note, provider_code, provider_sku, supplier_price,
  pricing_mode, margin_type, margin_value, is_active, sort_order
)
SELECT id, 'ml-59', '59 Diamonds', 17500, 'Populer', NULL, NULL, NULL,
       'manual', 'fixed', 0, 1, 3
FROM products
WHERE slug = 'mobile-legends';
--> statement-breakpoint
INSERT OR IGNORE INTO product_packages (
  product_id, sku, label, price, note, provider_code, provider_sku, supplier_price,
  pricing_mode, margin_type, margin_value, is_active, sort_order
)
SELECT id, 'ml-170', '170 Diamonds', 48000, NULL, NULL, NULL, NULL,
       'manual', 'fixed', 0, 1, 4
FROM products
WHERE slug = 'mobile-legends';
--> statement-breakpoint
INSERT OR IGNORE INTO product_packages (
  product_id, sku, label, price, note, provider_code, provider_sku, supplier_price,
  pricing_mode, margin_type, margin_value, is_active, sort_order
)
SELECT id, 'ml-weekly', 'Weekly Diamond Pass', 28500, NULL, NULL, NULL, NULL,
       'manual', 'fixed', 0, 1, 5
FROM products
WHERE slug = 'mobile-legends';
--> statement-breakpoint
INSERT OR IGNORE INTO products (
  slug, name, publisher, category, image_url, banner_url, description, initials, accent,
  input_label, input_placeholder, input_fields_json, needs_server, popular, instant,
  fulfillment_type, target_template, manual_instructions, manual_open_time, manual_close_time,
  manual_timezone, is_active, sort_order
) VALUES (
  'free-fire', 'Free Fire', 'Garena', 'game', '/products/free-fire-card.webp', '/products/free-fire-banner.webp', NULL, 'FF', 'from-[#ffad32] via-[#ea6825] to-[#7c2714]',
  'Player ID', 'Contoh: 1234567890', '[{"id":"account-id","label":"Player ID","placeholder":"Contoh: 1234567890","required":true}]', 0, 1, 1,
  'automatic', '{{destination}}', NULL, NULL, NULL,
  'Asia/Jakarta', 1, 1
);
--> statement-breakpoint
INSERT OR IGNORE INTO product_packages (
  product_id, sku, label, price, note, provider_code, provider_sku, supplier_price,
  pricing_mode, margin_type, margin_value, is_active, sort_order
)
SELECT id, 'ff-5', '5 Diamonds', 1500, NULL, NULL, NULL, NULL,
       'manual', 'fixed', 0, 1, 0
FROM products
WHERE slug = 'free-fire';
--> statement-breakpoint
INSERT OR IGNORE INTO product_packages (
  product_id, sku, label, price, note, provider_code, provider_sku, supplier_price,
  pricing_mode, margin_type, margin_value, is_active, sort_order
)
SELECT id, 'ff-20', '20 Diamonds', 4000, NULL, NULL, NULL, NULL,
       'manual', 'fixed', 0, 1, 1
FROM products
WHERE slug = 'free-fire';
--> statement-breakpoint
INSERT OR IGNORE INTO product_packages (
  product_id, sku, label, price, note, provider_code, provider_sku, supplier_price,
  pricing_mode, margin_type, margin_value, is_active, sort_order
)
SELECT id, 'ff-70', '70 Diamonds', 11000, 'Populer', NULL, NULL, NULL,
       'manual', 'fixed', 0, 1, 2
FROM products
WHERE slug = 'free-fire';
--> statement-breakpoint
INSERT OR IGNORE INTO product_packages (
  product_id, sku, label, price, note, provider_code, provider_sku, supplier_price,
  pricing_mode, margin_type, margin_value, is_active, sort_order
)
SELECT id, 'ff-140', '140 Diamonds', 20500, NULL, NULL, NULL, NULL,
       'manual', 'fixed', 0, 1, 3
FROM products
WHERE slug = 'free-fire';
--> statement-breakpoint
INSERT OR IGNORE INTO product_packages (
  product_id, sku, label, price, note, provider_code, provider_sku, supplier_price,
  pricing_mode, margin_type, margin_value, is_active, sort_order
)
SELECT id, 'ff-355', '355 Diamonds', 50500, NULL, NULL, NULL, NULL,
       'manual', 'fixed', 0, 1, 4
FROM products
WHERE slug = 'free-fire';
--> statement-breakpoint
INSERT OR IGNORE INTO product_packages (
  product_id, sku, label, price, note, provider_code, provider_sku, supplier_price,
  pricing_mode, margin_type, margin_value, is_active, sort_order
)
SELECT id, 'ff-member', 'Membership Mingguan', 28500, NULL, NULL, NULL, NULL,
       'manual', 'fixed', 0, 1, 5
FROM products
WHERE slug = 'free-fire';
--> statement-breakpoint
INSERT OR IGNORE INTO products (
  slug, name, publisher, category, image_url, banner_url, description, initials, accent,
  input_label, input_placeholder, input_fields_json, needs_server, popular, instant,
  fulfillment_type, target_template, manual_instructions, manual_open_time, manual_close_time,
  manual_timezone, is_active, sort_order
) VALUES (
  'pubg-mobile', 'PUBG Mobile', 'Level Infinite', 'game', '/products/pubg-mobile-card.webp', '/products/pubg-mobile-banner.webp', NULL, 'PM', 'from-[#f3ca52] via-[#b48624] to-[#4f3510]',
  'Player ID', 'Contoh: 51234567890', '[{"id":"account-id","label":"Player ID","placeholder":"Contoh: 51234567890","required":true}]', 0, 1, 1,
  'automatic', '{{destination}}', NULL, NULL, NULL,
  'Asia/Jakarta', 1, 2
);
--> statement-breakpoint
INSERT OR IGNORE INTO product_packages (
  product_id, sku, label, price, note, provider_code, provider_sku, supplier_price,
  pricing_mode, margin_type, margin_value, is_active, sort_order
)
SELECT id, 'pubg-60', '60 UC', 15500, NULL, NULL, NULL, NULL,
       'manual', 'fixed', 0, 1, 0
FROM products
WHERE slug = 'pubg-mobile';
--> statement-breakpoint
INSERT OR IGNORE INTO product_packages (
  product_id, sku, label, price, note, provider_code, provider_sku, supplier_price,
  pricing_mode, margin_type, margin_value, is_active, sort_order
)
SELECT id, 'pubg-325', '325 UC', 73500, 'Populer', NULL, NULL, NULL,
       'manual', 'fixed', 0, 1, 1
FROM products
WHERE slug = 'pubg-mobile';
--> statement-breakpoint
INSERT OR IGNORE INTO product_packages (
  product_id, sku, label, price, note, provider_code, provider_sku, supplier_price,
  pricing_mode, margin_type, margin_value, is_active, sort_order
)
SELECT id, 'pubg-660', '660 UC', 145000, NULL, NULL, NULL, NULL,
       'manual', 'fixed', 0, 1, 2
FROM products
WHERE slug = 'pubg-mobile';
--> statement-breakpoint
INSERT OR IGNORE INTO product_packages (
  product_id, sku, label, price, note, provider_code, provider_sku, supplier_price,
  pricing_mode, margin_type, margin_value, is_active, sort_order
)
SELECT id, 'pubg-1800', '1.800 UC', 358000, NULL, NULL, NULL, NULL,
       'manual', 'fixed', 0, 1, 3
FROM products
WHERE slug = 'pubg-mobile';
--> statement-breakpoint
INSERT OR IGNORE INTO products (
  slug, name, publisher, category, image_url, banner_url, description, initials, accent,
  input_label, input_placeholder, input_fields_json, needs_server, popular, instant,
  fulfillment_type, target_template, manual_instructions, manual_open_time, manual_close_time,
  manual_timezone, is_active, sort_order
) VALUES (
  'honor-of-kings', 'Honor of Kings', 'Level Infinite', 'game', '/products/honor-of-kings-card.webp', '/products/honor-of-kings-banner.webp', NULL, 'HK', 'from-[#f6d878] via-[#7c4fc9] to-[#2b174c]',
  'User ID', 'Masukkan User ID', '[{"id":"account-id","label":"User ID","placeholder":"Masukkan User ID","required":true}]', 0, 0, 1,
  'automatic', '{{destination}}', NULL, NULL, NULL,
  'Asia/Jakarta', 1, 3
);
--> statement-breakpoint
INSERT OR IGNORE INTO product_packages (
  product_id, sku, label, price, note, provider_code, provider_sku, supplier_price,
  pricing_mode, margin_type, margin_value, is_active, sort_order
)
SELECT id, 'hok-16', '16 Tokens', 4500, NULL, NULL, NULL, NULL,
       'manual', 'fixed', 0, 1, 0
FROM products
WHERE slug = 'honor-of-kings';
--> statement-breakpoint
INSERT OR IGNORE INTO product_packages (
  product_id, sku, label, price, note, provider_code, provider_sku, supplier_price,
  pricing_mode, margin_type, margin_value, is_active, sort_order
)
SELECT id, 'hok-80', '80 Tokens', 18500, NULL, NULL, NULL, NULL,
       'manual', 'fixed', 0, 1, 1
FROM products
WHERE slug = 'honor-of-kings';
--> statement-breakpoint
INSERT OR IGNORE INTO product_packages (
  product_id, sku, label, price, note, provider_code, provider_sku, supplier_price,
  pricing_mode, margin_type, margin_value, is_active, sort_order
)
SELECT id, 'hok-240', '240 Tokens', 52000, 'Populer', NULL, NULL, NULL,
       'manual', 'fixed', 0, 1, 2
FROM products
WHERE slug = 'honor-of-kings';
--> statement-breakpoint
INSERT OR IGNORE INTO product_packages (
  product_id, sku, label, price, note, provider_code, provider_sku, supplier_price,
  pricing_mode, margin_type, margin_value, is_active, sort_order
)
SELECT id, 'hok-400', '400 Tokens', 85500, NULL, NULL, NULL, NULL,
       'manual', 'fixed', 0, 1, 3
FROM products
WHERE slug = 'honor-of-kings';
--> statement-breakpoint
INSERT OR IGNORE INTO products (
  slug, name, publisher, category, image_url, banner_url, description, initials, accent,
  input_label, input_placeholder, input_fields_json, needs_server, popular, instant,
  fulfillment_type, target_template, manual_instructions, manual_open_time, manual_close_time,
  manual_timezone, is_active, sort_order
) VALUES (
  'genshin-impact', 'Genshin Impact', 'HoYoverse', 'game', '/products/genshin-impact-card.webp', '/products/genshin-impact-banner.webp', NULL, 'GI', 'from-[#87d7e7] via-[#597db9] to-[#242b5b]',
  'UID', 'Contoh: 800123456', '[{"id":"account-id","label":"UID","placeholder":"Contoh: 800123456","required":true}]', 0, 0, 0,
  'automatic', '{{destination}}', NULL, NULL, NULL,
  'Asia/Jakarta', 1, 4
);
--> statement-breakpoint
INSERT OR IGNORE INTO product_packages (
  product_id, sku, label, price, note, provider_code, provider_sku, supplier_price,
  pricing_mode, margin_type, margin_value, is_active, sort_order
)
SELECT id, 'gi-welkin', 'Blessing of the Welkin Moon', 59000, 'Populer', NULL, NULL, NULL,
       'manual', 'fixed', 0, 1, 0
FROM products
WHERE slug = 'genshin-impact';
--> statement-breakpoint
INSERT OR IGNORE INTO product_packages (
  product_id, sku, label, price, note, provider_code, provider_sku, supplier_price,
  pricing_mode, margin_type, margin_value, is_active, sort_order
)
SELECT id, 'gi-60', '60 Genesis Crystals', 15000, NULL, NULL, NULL, NULL,
       'manual', 'fixed', 0, 1, 1
FROM products
WHERE slug = 'genshin-impact';
--> statement-breakpoint
INSERT OR IGNORE INTO product_packages (
  product_id, sku, label, price, note, provider_code, provider_sku, supplier_price,
  pricing_mode, margin_type, margin_value, is_active, sort_order
)
SELECT id, 'gi-330', '330 Genesis Crystals', 75000, NULL, NULL, NULL, NULL,
       'manual', 'fixed', 0, 1, 2
FROM products
WHERE slug = 'genshin-impact';
--> statement-breakpoint
INSERT OR IGNORE INTO product_packages (
  product_id, sku, label, price, note, provider_code, provider_sku, supplier_price,
  pricing_mode, margin_type, margin_value, is_active, sort_order
)
SELECT id, 'gi-1090', '1.090 Genesis Crystals', 239000, NULL, NULL, NULL, NULL,
       'manual', 'fixed', 0, 1, 3
FROM products
WHERE slug = 'genshin-impact';
--> statement-breakpoint
INSERT OR IGNORE INTO products (
  slug, name, publisher, category, image_url, banner_url, description, initials, accent,
  input_label, input_placeholder, input_fields_json, needs_server, popular, instant,
  fulfillment_type, target_template, manual_instructions, manual_open_time, manual_close_time,
  manual_timezone, is_active, sort_order
) VALUES (
  'valorant', 'Valorant', 'Riot Games', 'game', '/products/valorant-card.webp', '/products/valorant-banner.webp', NULL, 'VL', 'from-[#ff5f65] via-[#c42f50] to-[#5b1530]',
  'Riot ID', 'Contoh: Player#TAG', '[{"id":"account-id","label":"Riot ID","placeholder":"Contoh: Player#TAG","required":true}]', 0, 0, 0,
  'automatic', '{{destination}}', NULL, NULL, NULL,
  'Asia/Jakarta', 1, 5
);
--> statement-breakpoint
INSERT OR IGNORE INTO product_packages (
  product_id, sku, label, price, note, provider_code, provider_sku, supplier_price,
  pricing_mode, margin_type, margin_value, is_active, sort_order
)
SELECT id, 'val-125', '125 Points', 16000, NULL, NULL, NULL, NULL,
       'manual', 'fixed', 0, 1, 0
FROM products
WHERE slug = 'valorant';
--> statement-breakpoint
INSERT OR IGNORE INTO product_packages (
  product_id, sku, label, price, note, provider_code, provider_sku, supplier_price,
  pricing_mode, margin_type, margin_value, is_active, sort_order
)
SELECT id, 'val-420', '420 Points', 50000, 'Populer', NULL, NULL, NULL,
       'manual', 'fixed', 0, 1, 1
FROM products
WHERE slug = 'valorant';
--> statement-breakpoint
INSERT OR IGNORE INTO product_packages (
  product_id, sku, label, price, note, provider_code, provider_sku, supplier_price,
  pricing_mode, margin_type, margin_value, is_active, sort_order
)
SELECT id, 'val-700', '700 Points', 80000, NULL, NULL, NULL, NULL,
       'manual', 'fixed', 0, 1, 2
FROM products
WHERE slug = 'valorant';
--> statement-breakpoint
INSERT OR IGNORE INTO product_packages (
  product_id, sku, label, price, note, provider_code, provider_sku, supplier_price,
  pricing_mode, margin_type, margin_value, is_active, sort_order
)
SELECT id, 'val-1375', '1.375 Points', 150000, NULL, NULL, NULL, NULL,
       'manual', 'fixed', 0, 1, 3
FROM products
WHERE slug = 'valorant';
--> statement-breakpoint
INSERT OR IGNORE INTO products (
  slug, name, publisher, category, image_url, banner_url, description, initials, accent,
  input_label, input_placeholder, input_fields_json, needs_server, popular, instant,
  fulfillment_type, target_template, manual_instructions, manual_open_time, manual_close_time,
  manual_timezone, is_active, sort_order
) VALUES (
  'steam-wallet', 'Steam Wallet', 'Valve', 'voucher', '/products/steam-wallet-card.webp', '/products/steam-wallet-banner.webp', NULL, 'SW', 'from-[#4da4d9] via-[#1b5a8c] to-[#10283b]',
  'Nomor WhatsApp / email', 'Untuk menerima kode voucher', '[{"id":"account-id","label":"Nomor WhatsApp / email","placeholder":"Untuk menerima kode voucher","required":true}]', 0, 1, 0,
  'automatic', '{{destination}}', NULL, NULL, NULL,
  'Asia/Jakarta', 1, 6
);
--> statement-breakpoint
INSERT OR IGNORE INTO product_packages (
  product_id, sku, label, price, note, provider_code, provider_sku, supplier_price,
  pricing_mode, margin_type, margin_value, is_active, sort_order
)
SELECT id, 'steam-12', 'Steam Wallet Rp12.000', 14000, NULL, NULL, NULL, NULL,
       'manual', 'fixed', 0, 1, 0
FROM products
WHERE slug = 'steam-wallet';
--> statement-breakpoint
INSERT OR IGNORE INTO product_packages (
  product_id, sku, label, price, note, provider_code, provider_sku, supplier_price,
  pricing_mode, margin_type, margin_value, is_active, sort_order
)
SELECT id, 'steam-45', 'Steam Wallet Rp45.000', 49000, 'Populer', NULL, NULL, NULL,
       'manual', 'fixed', 0, 1, 1
FROM products
WHERE slug = 'steam-wallet';
--> statement-breakpoint
INSERT OR IGNORE INTO product_packages (
  product_id, sku, label, price, note, provider_code, provider_sku, supplier_price,
  pricing_mode, margin_type, margin_value, is_active, sort_order
)
SELECT id, 'steam-90', 'Steam Wallet Rp90.000', 96000, NULL, NULL, NULL, NULL,
       'manual', 'fixed', 0, 1, 2
FROM products
WHERE slug = 'steam-wallet';
--> statement-breakpoint
INSERT OR IGNORE INTO product_packages (
  product_id, sku, label, price, note, provider_code, provider_sku, supplier_price,
  pricing_mode, margin_type, margin_value, is_active, sort_order
)
SELECT id, 'steam-120', 'Steam Wallet Rp120.000', 127000, NULL, NULL, NULL, NULL,
       'manual', 'fixed', 0, 1, 3
FROM products
WHERE slug = 'steam-wallet';
--> statement-breakpoint
INSERT OR IGNORE INTO products (
  slug, name, publisher, category, image_url, banner_url, description, initials, accent,
  input_label, input_placeholder, input_fields_json, needs_server, popular, instant,
  fulfillment_type, target_template, manual_instructions, manual_open_time, manual_close_time,
  manual_timezone, is_active, sort_order
) VALUES (
  'google-play', 'Google Play', 'Google', 'voucher', '/products/google-play-card.webp', '/products/google-play-banner.webp', NULL, 'GP', 'from-[#58d68d] via-[#2b8f9a] to-[#174862]',
  'Nomor WhatsApp / email', 'Untuk menerima kode voucher', '[{"id":"account-id","label":"Nomor WhatsApp / email","placeholder":"Untuk menerima kode voucher","required":true}]', 0, 0, 0,
  'automatic', '{{destination}}', NULL, NULL, NULL,
  'Asia/Jakarta', 1, 7
);
--> statement-breakpoint
INSERT OR IGNORE INTO product_packages (
  product_id, sku, label, price, note, provider_code, provider_sku, supplier_price,
  pricing_mode, margin_type, margin_value, is_active, sort_order
)
SELECT id, 'gp-20', 'Google Play Rp20.000', 22000, NULL, NULL, NULL, NULL,
       'manual', 'fixed', 0, 1, 0
FROM products
WHERE slug = 'google-play';
--> statement-breakpoint
INSERT OR IGNORE INTO product_packages (
  product_id, sku, label, price, note, provider_code, provider_sku, supplier_price,
  pricing_mode, margin_type, margin_value, is_active, sort_order
)
SELECT id, 'gp-50', 'Google Play Rp50.000', 54000, 'Populer', NULL, NULL, NULL,
       'manual', 'fixed', 0, 1, 1
FROM products
WHERE slug = 'google-play';
--> statement-breakpoint
INSERT OR IGNORE INTO product_packages (
  product_id, sku, label, price, note, provider_code, provider_sku, supplier_price,
  pricing_mode, margin_type, margin_value, is_active, sort_order
)
SELECT id, 'gp-100', 'Google Play Rp100.000', 107000, NULL, NULL, NULL, NULL,
       'manual', 'fixed', 0, 1, 2
FROM products
WHERE slug = 'google-play';
--> statement-breakpoint
INSERT OR IGNORE INTO product_packages (
  product_id, sku, label, price, note, provider_code, provider_sku, supplier_price,
  pricing_mode, margin_type, margin_value, is_active, sort_order
)
SELECT id, 'gp-150', 'Google Play Rp150.000', 160000, NULL, NULL, NULL, NULL,
       'manual', 'fixed', 0, 1, 3
FROM products
WHERE slug = 'google-play';
--> statement-breakpoint
INSERT OR IGNORE INTO products (
  slug, name, publisher, category, image_url, banner_url, description, initials, accent,
  input_label, input_placeholder, input_fields_json, needs_server, popular, instant,
  fulfillment_type, target_template, manual_instructions, manual_open_time, manual_close_time,
  manual_timezone, is_active, sort_order
) VALUES (
  'playstation-store', 'PlayStation Store', 'Sony', 'voucher', '/products/playstation-store-card.webp', '/products/playstation-store-banner.webp', NULL, 'PS', 'from-[#4c8fff] via-[#144fa8] to-[#12235d]',
  'Nomor WhatsApp / email', 'Untuk menerima kode voucher', '[{"id":"account-id","label":"Nomor WhatsApp / email","placeholder":"Untuk menerima kode voucher","required":true}]', 0, 0, 0,
  'automatic', '{{destination}}', NULL, NULL, NULL,
  'Asia/Jakarta', 1, 8
);
--> statement-breakpoint
INSERT OR IGNORE INTO product_packages (
  product_id, sku, label, price, note, provider_code, provider_sku, supplier_price,
  pricing_mode, margin_type, margin_value, is_active, sort_order
)
SELECT id, 'ps-100', 'PSN Rp100.000', 108000, NULL, NULL, NULL, NULL,
       'manual', 'fixed', 0, 1, 0
FROM products
WHERE slug = 'playstation-store';
--> statement-breakpoint
INSERT OR IGNORE INTO product_packages (
  product_id, sku, label, price, note, provider_code, provider_sku, supplier_price,
  pricing_mode, margin_type, margin_value, is_active, sort_order
)
SELECT id, 'ps-200', 'PSN Rp200.000', 214000, 'Populer', NULL, NULL, NULL,
       'manual', 'fixed', 0, 1, 1
FROM products
WHERE slug = 'playstation-store';
--> statement-breakpoint
INSERT OR IGNORE INTO product_packages (
  product_id, sku, label, price, note, provider_code, provider_sku, supplier_price,
  pricing_mode, margin_type, margin_value, is_active, sort_order
)
SELECT id, 'ps-400', 'PSN Rp400.000', 425000, NULL, NULL, NULL, NULL,
       'manual', 'fixed', 0, 1, 2
FROM products
WHERE slug = 'playstation-store';
--> statement-breakpoint
INSERT OR IGNORE INTO products (
  slug, name, publisher, category, image_url, banner_url, description, initials, accent,
  input_label, input_placeholder, input_fields_json, needs_server, popular, instant,
  fulfillment_type, target_template, manual_instructions, manual_open_time, manual_close_time,
  manual_timezone, is_active, sort_order
) VALUES (
  'roblox-gamepass', 'Roblox Gamepass', 'Roblox Corporation', 'game', '/products/roblox-gamepass-card.webp', '/products/roblox-gamepass-banner.webp', NULL, 'RG', 'from-[#6d7b91] via-[#303845] to-[#11151c]',
  'Link Gamepass', 'https://www.roblox.com/game-pass/...', '[{"id":"account-id","label":"Link Gamepass","placeholder":"https://www.roblox.com/game-pass/...","required":true}]', 0, 1, 0,
  'manual', '{{destination}}', 'Masukkan link Gamepass yang benar. Pesanan diperiksa admin dan diproses manual setelah pembayaran.', '09:00', '21:00',
  'Asia/Jakarta', 1, 9
);
--> statement-breakpoint
INSERT OR IGNORE INTO product_packages (
  product_id, sku, label, price, note, provider_code, provider_sku, supplier_price,
  pricing_mode, margin_type, margin_value, is_active, sort_order
)
SELECT id, 'roblox-gp-100', '100 Robux via Gamepass', 20000, 'Manual', NULL, NULL, NULL,
       'manual', 'fixed', 0, 1, 0
FROM products
WHERE slug = 'roblox-gamepass';
--> statement-breakpoint
INSERT OR IGNORE INTO product_packages (
  product_id, sku, label, price, note, provider_code, provider_sku, supplier_price,
  pricing_mode, margin_type, margin_value, is_active, sort_order
)
SELECT id, 'roblox-gp-500', '500 Robux via Gamepass', 95000, NULL, NULL, NULL, NULL,
       'manual', 'fixed', 0, 1, 1
FROM products
WHERE slug = 'roblox-gamepass';
--> statement-breakpoint
INSERT OR IGNORE INTO product_packages (
  product_id, sku, label, price, note, provider_code, provider_sku, supplier_price,
  pricing_mode, margin_type, margin_value, is_active, sort_order
)
SELECT id, 'roblox-gp-1000', '1.000 Robux via Gamepass', 185000, NULL, NULL, NULL, NULL,
       'manual', 'fixed', 0, 1, 2
FROM products
WHERE slug = 'roblox-gamepass';
--> statement-breakpoint
INSERT INTO product_notices (product_id, title, body, is_active, sort_order)
SELECT p.id, 'JAM OPERASIONAL {{jam_buka}} – {{jam_tutup}} {{zona_waktu}}', 'Estimasi proses 30 menit sampai 2 jam.

Produk ini diproses manual. Admin akan menghubungi melalui WhatsApp setelah pembayaran berhasil.', 1, 0
FROM products p
WHERE p.slug = 'roblox-gamepass'
  AND NOT EXISTS (
    SELECT 1 FROM product_notices n
    WHERE n.product_id = p.id AND n.title = 'JAM OPERASIONAL {{jam_buka}} – {{jam_tutup}} {{zona_waktu}}' AND n.body = 'Estimasi proses 30 menit sampai 2 jam.

Produk ini diproses manual. Admin akan menghubungi melalui WhatsApp setelah pembayaran berhasil.'
  );
--> statement-breakpoint
INSERT OR IGNORE INTO products (
  slug, name, publisher, category, image_url, banner_url, description, initials, accent,
  input_label, input_placeholder, input_fields_json, needs_server, popular, instant,
  fulfillment_type, target_template, manual_instructions, manual_open_time, manual_close_time,
  manual_timezone, is_active, sort_order
) VALUES (
  'roblox-gift-in-game', 'Roblox Gift in Game', 'Roblox Corporation', 'game', '/products/roblox-gift-in-game-card.webp', '/products/roblox-gift-in-game-banner.webp', NULL, 'RI', 'from-[#ff5a5f] via-[#a6213f] to-[#35101f]',
  'Username Roblox', 'Masukkan username Roblox', '[{"id":"account-id","label":"Username Roblox","placeholder":"Masukkan username Roblox","required":true}]', 0, 0, 0,
  'manual', '{{destination}}', 'Masukkan username dan nama item yang ingin diterima. Admin akan menghubungi melalui WhatsApp untuk jadwal pengiriman.', '09:00', '21:00',
  'Asia/Jakarta', 1, 10
);
--> statement-breakpoint
INSERT OR IGNORE INTO product_packages (
  product_id, sku, label, price, note, provider_code, provider_sku, supplier_price,
  pricing_mode, margin_type, margin_value, is_active, sort_order
)
SELECT id, 'roblox-gift-small', 'Gift in Game — Paket S', 25000, 'Manual', NULL, NULL, NULL,
       'manual', 'fixed', 0, 1, 0
FROM products
WHERE slug = 'roblox-gift-in-game';
--> statement-breakpoint
INSERT OR IGNORE INTO product_packages (
  product_id, sku, label, price, note, provider_code, provider_sku, supplier_price,
  pricing_mode, margin_type, margin_value, is_active, sort_order
)
SELECT id, 'roblox-gift-medium', 'Gift in Game — Paket M', 50000, NULL, NULL, NULL, NULL,
       'manual', 'fixed', 0, 1, 1
FROM products
WHERE slug = 'roblox-gift-in-game';
--> statement-breakpoint
INSERT OR IGNORE INTO product_packages (
  product_id, sku, label, price, note, provider_code, provider_sku, supplier_price,
  pricing_mode, margin_type, margin_value, is_active, sort_order
)
SELECT id, 'roblox-gift-large', 'Gift in Game — Paket L', 100000, NULL, NULL, NULL, NULL,
       'manual', 'fixed', 0, 1, 2
FROM products
WHERE slug = 'roblox-gift-in-game';
--> statement-breakpoint
INSERT INTO product_notices (product_id, title, body, is_active, sort_order)
SELECT p.id, 'JAM OPERASIONAL {{jam_buka}} – {{jam_tutup}} {{zona_waktu}}', 'Estimasi proses mengikuti antrean admin.

Pastikan username dan nama item sudah benar. Admin akan menghubungi melalui WhatsApp setelah pembayaran berhasil.', 1, 0
FROM products p
WHERE p.slug = 'roblox-gift-in-game'
  AND NOT EXISTS (
    SELECT 1 FROM product_notices n
    WHERE n.product_id = p.id AND n.title = 'JAM OPERASIONAL {{jam_buka}} – {{jam_tutup}} {{zona_waktu}}' AND n.body = 'Estimasi proses mengikuti antrean admin.

Pastikan username dan nama item sudah benar. Admin akan menghubungi melalui WhatsApp setelah pembayaran berhasil.'
  );
--> statement-breakpoint
INSERT OR IGNORE INTO products (
  slug, name, publisher, category, image_url, banner_url, description, initials, accent,
  input_label, input_placeholder, input_fields_json, needs_server, popular, instant,
  fulfillment_type, target_template, manual_instructions, manual_open_time, manual_close_time,
  manual_timezone, is_active, sort_order
) VALUES (
  'roblox-via-login', 'Roblox Via Login', 'Roblox Corporation', 'game', '/products/roblox-via-login-card.webp', '/products/roblox-via-login-banner.webp', NULL, 'RL', 'from-[#8d69ff] via-[#5531a5] to-[#24154d]',
  'Username Roblox (tanpa password)', 'Masukkan username Roblox', '[{"id":"account-id","label":"Username Roblox (tanpa password)","placeholder":"Masukkan username Roblox","required":true}]', 0, 0, 0,
  'manual', '{{destination}}', 'Masukkan username saja—jangan pernah masukkan password atau kode OTP di website. Detail aman akan dikonfirmasi admin melalui WhatsApp.', '09:00', '21:00',
  'Asia/Jakarta', 1, 11
);
--> statement-breakpoint
INSERT OR IGNORE INTO product_packages (
  product_id, sku, label, price, note, provider_code, provider_sku, supplier_price,
  pricing_mode, margin_type, margin_value, is_active, sort_order
)
SELECT id, 'roblox-login-100', '100 Robux Via Login', 18000, 'Manual', NULL, NULL, NULL,
       'manual', 'fixed', 0, 1, 0
FROM products
WHERE slug = 'roblox-via-login';
--> statement-breakpoint
INSERT OR IGNORE INTO product_packages (
  product_id, sku, label, price, note, provider_code, provider_sku, supplier_price,
  pricing_mode, margin_type, margin_value, is_active, sort_order
)
SELECT id, 'roblox-login-500', '500 Robux Via Login', 85000, NULL, NULL, NULL, NULL,
       'manual', 'fixed', 0, 1, 1
FROM products
WHERE slug = 'roblox-via-login';
--> statement-breakpoint
INSERT OR IGNORE INTO product_packages (
  product_id, sku, label, price, note, provider_code, provider_sku, supplier_price,
  pricing_mode, margin_type, margin_value, is_active, sort_order
)
SELECT id, 'roblox-login-1000', '1.000 Robux Via Login', 165000, NULL, NULL, NULL, NULL,
       'manual', 'fixed', 0, 1, 2
FROM products
WHERE slug = 'roblox-via-login';
--> statement-breakpoint
INSERT INTO product_notices (product_id, title, body, is_active, sort_order)
SELECT p.id, 'JAM OPERASIONAL {{jam_buka}} – {{jam_tutup}} {{zona_waktu}}', 'Estimasi proses 30 menit sampai 2 jam.

Produk ini diproses via login. Jangan pernah mengirim OTP melalui form website; admin akan menghubungi melalui WhatsApp setelah pembayaran berhasil.', 1, 0
FROM products p
WHERE p.slug = 'roblox-via-login'
  AND NOT EXISTS (
    SELECT 1 FROM product_notices n
    WHERE n.product_id = p.id AND n.title = 'JAM OPERASIONAL {{jam_buka}} – {{jam_tutup}} {{zona_waktu}}' AND n.body = 'Estimasi proses 30 menit sampai 2 jam.

Produk ini diproses via login. Jangan pernah mengirim OTP melalui form website; admin akan menghubungi melalui WhatsApp setelah pembayaran berhasil.'
  );
--> statement-breakpoint
INSERT OR IGNORE INTO products (
  slug, name, publisher, category, image_url, banner_url, description, initials, accent,
  input_label, input_placeholder, input_fields_json, needs_server, popular, instant,
  fulfillment_type, target_template, manual_instructions, manual_open_time, manual_close_time,
  manual_timezone, is_active, sort_order
) VALUES (
  'call-of-duty-mobile', 'Call of Duty Mobile', 'Activision', 'game', '/products/call-of-duty-mobile-card.webp', '/products/call-of-duty-mobile-banner.webp', NULL, 'COD', 'from-[#f5cf55] via-[#6d5b25] to-[#17150d]',
  'Player ID', 'Masukkan Player ID', '[{"id":"account-id","label":"Player ID","placeholder":"Masukkan Player ID","required":true}]', 0, 1, 1,
  'automatic', '{{destination}}', NULL, NULL, NULL,
  'Asia/Jakarta', 1, 12
);
--> statement-breakpoint
INSERT OR IGNORE INTO product_packages (
  product_id, sku, label, price, note, provider_code, provider_sku, supplier_price,
  pricing_mode, margin_type, margin_value, is_active, sort_order
)
SELECT id, 'codm-31', '31 CP', 6000, NULL, NULL, NULL, NULL,
       'manual', 'fixed', 0, 1, 0
FROM products
WHERE slug = 'call-of-duty-mobile';
--> statement-breakpoint
INSERT OR IGNORE INTO product_packages (
  product_id, sku, label, price, note, provider_code, provider_sku, supplier_price,
  pricing_mode, margin_type, margin_value, is_active, sort_order
)
SELECT id, 'codm-63', '63 CP', 11500, NULL, NULL, NULL, NULL,
       'manual', 'fixed', 0, 1, 1
FROM products
WHERE slug = 'call-of-duty-mobile';
--> statement-breakpoint
INSERT OR IGNORE INTO product_packages (
  product_id, sku, label, price, note, provider_code, provider_sku, supplier_price,
  pricing_mode, margin_type, margin_value, is_active, sort_order
)
SELECT id, 'codm-128', '128 CP', 22500, 'Populer', NULL, NULL, NULL,
       'manual', 'fixed', 0, 1, 2
FROM products
WHERE slug = 'call-of-duty-mobile';
--> statement-breakpoint
INSERT OR IGNORE INTO product_packages (
  product_id, sku, label, price, note, provider_code, provider_sku, supplier_price,
  pricing_mode, margin_type, margin_value, is_active, sort_order
)
SELECT id, 'codm-645', '645 CP', 108000, NULL, NULL, NULL, NULL,
       'manual', 'fixed', 0, 1, 3
FROM products
WHERE slug = 'call-of-duty-mobile';
--> statement-breakpoint
INSERT OR IGNORE INTO products (
  slug, name, publisher, category, image_url, banner_url, description, initials, accent,
  input_label, input_placeholder, input_fields_json, needs_server, popular, instant,
  fulfillment_type, target_template, manual_instructions, manual_open_time, manual_close_time,
  manual_timezone, is_active, sort_order
) VALUES (
  'wild-rift', 'League of Legends: Wild Rift', 'Riot Games', 'game', '/products/wild-rift-card.webp', '/products/wild-rift-banner.webp', NULL, 'WR', 'from-[#59d7e8] via-[#196b9c] to-[#112951]',
  'Riot ID', 'Contoh: Player#TAG', '[{"id":"account-id","label":"Riot ID","placeholder":"Contoh: Player#TAG","required":true}]', 0, 0, 1,
  'automatic', '{{destination}}', NULL, NULL, NULL,
  'Asia/Jakarta', 1, 13
);
--> statement-breakpoint
INSERT OR IGNORE INTO product_packages (
  product_id, sku, label, price, note, provider_code, provider_sku, supplier_price,
  pricing_mode, margin_type, margin_value, is_active, sort_order
)
SELECT id, 'wr-425', '425 Wild Cores', 49000, NULL, NULL, NULL, NULL,
       'manual', 'fixed', 0, 1, 0
FROM products
WHERE slug = 'wild-rift';
--> statement-breakpoint
INSERT OR IGNORE INTO product_packages (
  product_id, sku, label, price, note, provider_code, provider_sku, supplier_price,
  pricing_mode, margin_type, margin_value, is_active, sort_order
)
SELECT id, 'wr-1000', '1.000 Wild Cores', 109000, 'Populer', NULL, NULL, NULL,
       'manual', 'fixed', 0, 1, 1
FROM products
WHERE slug = 'wild-rift';
--> statement-breakpoint
INSERT OR IGNORE INTO product_packages (
  product_id, sku, label, price, note, provider_code, provider_sku, supplier_price,
  pricing_mode, margin_type, margin_value, is_active, sort_order
)
SELECT id, 'wr-2050', '2.050 Wild Cores', 219000, NULL, NULL, NULL, NULL,
       'manual', 'fixed', 0, 1, 2
FROM products
WHERE slug = 'wild-rift';
--> statement-breakpoint
INSERT OR IGNORE INTO products (
  slug, name, publisher, category, image_url, banner_url, description, initials, accent,
  input_label, input_placeholder, input_fields_json, needs_server, popular, instant,
  fulfillment_type, target_template, manual_instructions, manual_open_time, manual_close_time,
  manual_timezone, is_active, sort_order
) VALUES (
  'arena-of-valor', 'Arena of Valor', 'Garena', 'game', '/products/arena-of-valor-card.webp', '/products/arena-of-valor-banner.webp', NULL, 'AOV', 'from-[#eecc72] via-[#7b4d2b] to-[#251711]',
  'Player ID', 'Masukkan Player ID', '[{"id":"account-id","label":"Player ID","placeholder":"Masukkan Player ID","required":true}]', 0, 0, 1,
  'automatic', '{{destination}}', NULL, NULL, NULL,
  'Asia/Jakarta', 1, 14
);
--> statement-breakpoint
INSERT OR IGNORE INTO product_packages (
  product_id, sku, label, price, note, provider_code, provider_sku, supplier_price,
  pricing_mode, margin_type, margin_value, is_active, sort_order
)
SELECT id, 'aov-40', '40 Vouchers', 10000, NULL, NULL, NULL, NULL,
       'manual', 'fixed', 0, 1, 0
FROM products
WHERE slug = 'arena-of-valor';
--> statement-breakpoint
INSERT OR IGNORE INTO product_packages (
  product_id, sku, label, price, note, provider_code, provider_sku, supplier_price,
  pricing_mode, margin_type, margin_value, is_active, sort_order
)
SELECT id, 'aov-90', '90 Vouchers', 21000, NULL, NULL, NULL, NULL,
       'manual', 'fixed', 0, 1, 1
FROM products
WHERE slug = 'arena-of-valor';
--> statement-breakpoint
INSERT OR IGNORE INTO product_packages (
  product_id, sku, label, price, note, provider_code, provider_sku, supplier_price,
  pricing_mode, margin_type, margin_value, is_active, sort_order
)
SELECT id, 'aov-230', '230 Vouchers', 51000, 'Populer', NULL, NULL, NULL,
       'manual', 'fixed', 0, 1, 2
FROM products
WHERE slug = 'arena-of-valor';
--> statement-breakpoint
INSERT OR IGNORE INTO products (
  slug, name, publisher, category, image_url, banner_url, description, initials, accent,
  input_label, input_placeholder, input_fields_json, needs_server, popular, instant,
  fulfillment_type, target_template, manual_instructions, manual_open_time, manual_close_time,
  manual_timezone, is_active, sort_order
) VALUES (
  'fc-mobile', 'EA SPORTS FC Mobile', 'Electronic Arts', 'game', '/products/fc-mobile-card.webp', '/products/fc-mobile-banner.webp', NULL, 'FC', 'from-[#54e884] via-[#16835c] to-[#0b2821]',
  'User ID', 'Masukkan User ID', '[{"id":"account-id","label":"User ID","placeholder":"Masukkan User ID","required":true}]', 0, 0, 1,
  'automatic', '{{destination}}', NULL, NULL, NULL,
  'Asia/Jakarta', 1, 15
);
--> statement-breakpoint
INSERT OR IGNORE INTO product_packages (
  product_id, sku, label, price, note, provider_code, provider_sku, supplier_price,
  pricing_mode, margin_type, margin_value, is_active, sort_order
)
SELECT id, 'fcm-40', '40 FC Points', 9000, NULL, NULL, NULL, NULL,
       'manual', 'fixed', 0, 1, 0
FROM products
WHERE slug = 'fc-mobile';
--> statement-breakpoint
INSERT OR IGNORE INTO product_packages (
  product_id, sku, label, price, note, provider_code, provider_sku, supplier_price,
  pricing_mode, margin_type, margin_value, is_active, sort_order
)
SELECT id, 'fcm-100', '100 FC Points', 21000, NULL, NULL, NULL, NULL,
       'manual', 'fixed', 0, 1, 1
FROM products
WHERE slug = 'fc-mobile';
--> statement-breakpoint
INSERT OR IGNORE INTO product_packages (
  product_id, sku, label, price, note, provider_code, provider_sku, supplier_price,
  pricing_mode, margin_type, margin_value, is_active, sort_order
)
SELECT id, 'fcm-520', '520 FC Points', 99000, 'Populer', NULL, NULL, NULL,
       'manual', 'fixed', 0, 1, 2
FROM products
WHERE slug = 'fc-mobile';
--> statement-breakpoint
INSERT OR IGNORE INTO products (
  slug, name, publisher, category, image_url, banner_url, description, initials, accent,
  input_label, input_placeholder, input_fields_json, needs_server, popular, instant,
  fulfillment_type, target_template, manual_instructions, manual_open_time, manual_close_time,
  manual_timezone, is_active, sort_order
) VALUES (
  'efootball', 'eFootball', 'Konami', 'game', '/products/efootball-card.webp', '/products/efootball-banner.webp', NULL, 'EF', 'from-[#397cff] via-[#4531c8] to-[#171450]',
  'User ID', 'Masukkan User ID eFootball', '[{"id":"account-id","label":"User ID","placeholder":"Masukkan User ID eFootball","required":true}]', 0, 0, 1,
  'automatic', '{{destination}}', NULL, NULL, NULL,
  'Asia/Jakarta', 1, 16
);
--> statement-breakpoint
INSERT OR IGNORE INTO product_packages (
  product_id, sku, label, price, note, provider_code, provider_sku, supplier_price,
  pricing_mode, margin_type, margin_value, is_active, sort_order
)
SELECT id, 'ef-130', '130 Coins', 19000, NULL, NULL, NULL, NULL,
       'manual', 'fixed', 0, 1, 0
FROM products
WHERE slug = 'efootball';
--> statement-breakpoint
INSERT OR IGNORE INTO product_packages (
  product_id, sku, label, price, note, provider_code, provider_sku, supplier_price,
  pricing_mode, margin_type, margin_value, is_active, sort_order
)
SELECT id, 'ef-300', '300 Coins', 42000, 'Populer', NULL, NULL, NULL,
       'manual', 'fixed', 0, 1, 1
FROM products
WHERE slug = 'efootball';
--> statement-breakpoint
INSERT OR IGNORE INTO product_packages (
  product_id, sku, label, price, note, provider_code, provider_sku, supplier_price,
  pricing_mode, margin_type, margin_value, is_active, sort_order
)
SELECT id, 'ef-550', '550 Coins', 75000, NULL, NULL, NULL, NULL,
       'manual', 'fixed', 0, 1, 2
FROM products
WHERE slug = 'efootball';
--> statement-breakpoint
INSERT OR IGNORE INTO products (
  slug, name, publisher, category, image_url, banner_url, description, initials, accent,
  input_label, input_placeholder, input_fields_json, needs_server, popular, instant,
  fulfillment_type, target_template, manual_instructions, manual_open_time, manual_close_time,
  manual_timezone, is_active, sort_order
) VALUES (
  'point-blank', 'Point Blank', 'Zepetto', 'game', '/products/point-blank-card.webp', '/products/point-blank-banner.webp', NULL, 'PB', 'from-[#ef6d55] via-[#8a2d2a] to-[#2c1112]',
  'User ID', 'Masukkan User ID Point Blank', '[{"id":"account-id","label":"User ID","placeholder":"Masukkan User ID Point Blank","required":true}]', 0, 0, 1,
  'automatic', '{{destination}}', NULL, NULL, NULL,
  'Asia/Jakarta', 1, 17
);
--> statement-breakpoint
INSERT OR IGNORE INTO product_packages (
  product_id, sku, label, price, note, provider_code, provider_sku, supplier_price,
  pricing_mode, margin_type, margin_value, is_active, sort_order
)
SELECT id, 'pb-1200', '1.200 Cash', 10000, NULL, NULL, NULL, NULL,
       'manual', 'fixed', 0, 1, 0
FROM products
WHERE slug = 'point-blank';
--> statement-breakpoint
INSERT OR IGNORE INTO product_packages (
  product_id, sku, label, price, note, provider_code, provider_sku, supplier_price,
  pricing_mode, margin_type, margin_value, is_active, sort_order
)
SELECT id, 'pb-2400', '2.400 Cash', 20000, NULL, NULL, NULL, NULL,
       'manual', 'fixed', 0, 1, 1
FROM products
WHERE slug = 'point-blank';
--> statement-breakpoint
INSERT OR IGNORE INTO product_packages (
  product_id, sku, label, price, note, provider_code, provider_sku, supplier_price,
  pricing_mode, margin_type, margin_value, is_active, sort_order
)
SELECT id, 'pb-6000', '6.000 Cash', 50000, 'Populer', NULL, NULL, NULL,
       'manual', 'fixed', 0, 1, 2
FROM products
WHERE slug = 'point-blank';
--> statement-breakpoint
INSERT OR IGNORE INTO products (
  slug, name, publisher, category, image_url, banner_url, description, initials, accent,
  input_label, input_placeholder, input_fields_json, needs_server, popular, instant,
  fulfillment_type, target_template, manual_instructions, manual_open_time, manual_close_time,
  manual_timezone, is_active, sort_order
) VALUES (
  'garena-shells', 'Garena Shells', 'Garena', 'voucher', '/products/garena-shells-card.webp', '/products/garena-shells-banner.webp', NULL, 'GS', 'from-[#ee524b] via-[#a51f28] to-[#390d16]',
  'Nomor WhatsApp / email', 'Untuk menerima kode voucher', '[{"id":"account-id","label":"Nomor WhatsApp / email","placeholder":"Untuk menerima kode voucher","required":true}]', 0, 1, 1,
  'automatic', '{{destination}}', NULL, NULL, NULL,
  'Asia/Jakarta', 1, 18
);
--> statement-breakpoint
INSERT OR IGNORE INTO product_packages (
  product_id, sku, label, price, note, provider_code, provider_sku, supplier_price,
  pricing_mode, margin_type, margin_value, is_active, sort_order
)
SELECT id, 'gs-33', '33 Shells', 11000, NULL, NULL, NULL, NULL,
       'manual', 'fixed', 0, 1, 0
FROM products
WHERE slug = 'garena-shells';
--> statement-breakpoint
INSERT OR IGNORE INTO product_packages (
  product_id, sku, label, price, note, provider_code, provider_sku, supplier_price,
  pricing_mode, margin_type, margin_value, is_active, sort_order
)
SELECT id, 'gs-66', '66 Shells', 21000, NULL, NULL, NULL, NULL,
       'manual', 'fixed', 0, 1, 1
FROM products
WHERE slug = 'garena-shells';
--> statement-breakpoint
INSERT OR IGNORE INTO product_packages (
  product_id, sku, label, price, note, provider_code, provider_sku, supplier_price,
  pricing_mode, margin_type, margin_value, is_active, sort_order
)
SELECT id, 'gs-165', '165 Shells', 51000, 'Populer', NULL, NULL, NULL,
       'manual', 'fixed', 0, 1, 2
FROM products
WHERE slug = 'garena-shells';
--> statement-breakpoint
INSERT OR IGNORE INTO products (
  slug, name, publisher, category, image_url, banner_url, description, initials, accent,
  input_label, input_placeholder, input_fields_json, needs_server, popular, instant,
  fulfillment_type, target_template, manual_instructions, manual_open_time, manual_close_time,
  manual_timezone, is_active, sort_order
) VALUES (
  'razer-gold', 'Razer Gold', 'Razer', 'voucher', '/products/razer-gold-card.webp', '/products/razer-gold-banner.webp', NULL, 'RZ', 'from-[#7dff59] via-[#19813a] to-[#0d2c20]',
  'Nomor WhatsApp / email', 'Untuk menerima PIN voucher', '[{"id":"account-id","label":"Nomor WhatsApp / email","placeholder":"Untuk menerima PIN voucher","required":true}]', 0, 0, 1,
  'automatic', '{{destination}}', NULL, NULL, NULL,
  'Asia/Jakarta', 1, 19
);
--> statement-breakpoint
INSERT OR IGNORE INTO product_packages (
  product_id, sku, label, price, note, provider_code, provider_sku, supplier_price,
  pricing_mode, margin_type, margin_value, is_active, sort_order
)
SELECT id, 'rz-20', 'Razer Gold Rp20.000', 22000, NULL, NULL, NULL, NULL,
       'manual', 'fixed', 0, 1, 0
FROM products
WHERE slug = 'razer-gold';
--> statement-breakpoint
INSERT OR IGNORE INTO product_packages (
  product_id, sku, label, price, note, provider_code, provider_sku, supplier_price,
  pricing_mode, margin_type, margin_value, is_active, sort_order
)
SELECT id, 'rz-50', 'Razer Gold Rp50.000', 53500, 'Populer', NULL, NULL, NULL,
       'manual', 'fixed', 0, 1, 1
FROM products
WHERE slug = 'razer-gold';
--> statement-breakpoint
INSERT OR IGNORE INTO product_packages (
  product_id, sku, label, price, note, provider_code, provider_sku, supplier_price,
  pricing_mode, margin_type, margin_value, is_active, sort_order
)
SELECT id, 'rz-100', 'Razer Gold Rp100.000', 106000, NULL, NULL, NULL, NULL,
       'manual', 'fixed', 0, 1, 2
FROM products
WHERE slug = 'razer-gold';
--> statement-breakpoint
INSERT OR IGNORE INTO products (
  slug, name, publisher, category, image_url, banner_url, description, initials, accent,
  input_label, input_placeholder, input_fields_json, needs_server, popular, instant,
  fulfillment_type, target_template, manual_instructions, manual_open_time, manual_close_time,
  manual_timezone, is_active, sort_order
) VALUES (
  'unipin-voucher', 'UniPin Voucher', 'UniPin', 'voucher', '/products/unipin-voucher-card.webp', '/products/unipin-voucher-banner.webp', NULL, 'UP', 'from-[#ff785a] via-[#bf3e4e] to-[#43172b]',
  'Nomor WhatsApp / email', 'Untuk menerima kode voucher', '[{"id":"account-id","label":"Nomor WhatsApp / email","placeholder":"Untuk menerima kode voucher","required":true}]', 0, 0, 1,
  'automatic', '{{destination}}', NULL, NULL, NULL,
  'Asia/Jakarta', 1, 20
);
--> statement-breakpoint
INSERT OR IGNORE INTO product_packages (
  product_id, sku, label, price, note, provider_code, provider_sku, supplier_price,
  pricing_mode, margin_type, margin_value, is_active, sort_order
)
SELECT id, 'up-20', 'UniPin Rp20.000', 22000, NULL, NULL, NULL, NULL,
       'manual', 'fixed', 0, 1, 0
FROM products
WHERE slug = 'unipin-voucher';
--> statement-breakpoint
INSERT OR IGNORE INTO product_packages (
  product_id, sku, label, price, note, provider_code, provider_sku, supplier_price,
  pricing_mode, margin_type, margin_value, is_active, sort_order
)
SELECT id, 'up-50', 'UniPin Rp50.000', 53500, NULL, NULL, NULL, NULL,
       'manual', 'fixed', 0, 1, 1
FROM products
WHERE slug = 'unipin-voucher';
--> statement-breakpoint
INSERT OR IGNORE INTO product_packages (
  product_id, sku, label, price, note, provider_code, provider_sku, supplier_price,
  pricing_mode, margin_type, margin_value, is_active, sort_order
)
SELECT id, 'up-100', 'UniPin Rp100.000', 106000, 'Populer', NULL, NULL, NULL,
       'manual', 'fixed', 0, 1, 2
FROM products
WHERE slug = 'unipin-voucher';
--> statement-breakpoint
INSERT OR IGNORE INTO products (
  slug, name, publisher, category, image_url, banner_url, description, initials, accent,
  input_label, input_placeholder, input_fields_json, needs_server, popular, instant,
  fulfillment_type, target_template, manual_instructions, manual_open_time, manual_close_time,
  manual_timezone, is_active, sort_order
) VALUES (
  'xbox-gift-card', 'Xbox Gift Card', 'Microsoft', 'voucher', '/products/xbox-gift-card-card.webp', '/products/xbox-gift-card-banner.webp', NULL, 'XB', 'from-[#69cc67] via-[#248239] to-[#14341d]',
  'Nomor WhatsApp / email', 'Untuk menerima kode voucher', '[{"id":"account-id","label":"Nomor WhatsApp / email","placeholder":"Untuk menerima kode voucher","required":true}]', 0, 0, 1,
  'automatic', '{{destination}}', NULL, NULL, NULL,
  'Asia/Jakarta', 1, 21
);
--> statement-breakpoint
INSERT OR IGNORE INTO product_packages (
  product_id, sku, label, price, note, provider_code, provider_sku, supplier_price,
  pricing_mode, margin_type, margin_value, is_active, sort_order
)
SELECT id, 'xbox-100', 'Xbox Rp100.000', 109000, NULL, NULL, NULL, NULL,
       'manual', 'fixed', 0, 1, 0
FROM products
WHERE slug = 'xbox-gift-card';
--> statement-breakpoint
INSERT OR IGNORE INTO product_packages (
  product_id, sku, label, price, note, provider_code, provider_sku, supplier_price,
  pricing_mode, margin_type, margin_value, is_active, sort_order
)
SELECT id, 'xbox-200', 'Xbox Rp200.000', 216000, 'Populer', NULL, NULL, NULL,
       'manual', 'fixed', 0, 1, 1
FROM products
WHERE slug = 'xbox-gift-card';
--> statement-breakpoint
INSERT OR IGNORE INTO products (
  slug, name, publisher, category, image_url, banner_url, description, initials, accent,
  input_label, input_placeholder, input_fields_json, needs_server, popular, instant,
  fulfillment_type, target_template, manual_instructions, manual_open_time, manual_close_time,
  manual_timezone, is_active, sort_order
) VALUES (
  'nintendo-eshop', 'Nintendo eShop', 'Nintendo', 'voucher', '/products/nintendo-eshop-card.webp', '/products/nintendo-eshop-banner.webp', NULL, 'NS', 'from-[#ff6969] via-[#c83045] to-[#481522]',
  'Nomor WhatsApp / email', 'Untuk menerima kode voucher', '[{"id":"account-id","label":"Nomor WhatsApp / email","placeholder":"Untuk menerima kode voucher","required":true}]', 0, 0, 1,
  'automatic', '{{destination}}', NULL, NULL, NULL,
  'Asia/Jakarta', 1, 22
);
--> statement-breakpoint
INSERT OR IGNORE INTO product_packages (
  product_id, sku, label, price, note, provider_code, provider_sku, supplier_price,
  pricing_mode, margin_type, margin_value, is_active, sort_order
)
SELECT id, 'nintendo-10', 'Nintendo eShop $10', 175000, NULL, NULL, NULL, NULL,
       'manual', 'fixed', 0, 1, 0
FROM products
WHERE slug = 'nintendo-eshop';
--> statement-breakpoint
INSERT OR IGNORE INTO product_packages (
  product_id, sku, label, price, note, provider_code, provider_sku, supplier_price,
  pricing_mode, margin_type, margin_value, is_active, sort_order
)
SELECT id, 'nintendo-20', 'Nintendo eShop $20', 338000, 'Populer', NULL, NULL, NULL,
       'manual', 'fixed', 0, 1, 1
FROM products
WHERE slug = 'nintendo-eshop';
--> statement-breakpoint
INSERT OR IGNORE INTO products (
  slug, name, publisher, category, image_url, banner_url, description, initials, accent,
  input_label, input_placeholder, input_fields_json, needs_server, popular, instant,
  fulfillment_type, target_template, manual_instructions, manual_open_time, manual_close_time,
  manual_timezone, is_active, sort_order
) VALUES (
  'redfinger', 'REDFINGER Cloud Phone', 'REDFINGER', 'voucher', '/products/redfinger-card.webp', '/products/redfinger-banner.webp', NULL, 'RF', 'from-[#ff576c] via-[#962d68] to-[#321947]',
  'Email tujuan', 'Email untuk menerima lisensi', '[{"id":"account-id","label":"Email tujuan","placeholder":"Email untuk menerima lisensi","required":true}]', 0, 1, 1,
  'automatic', '{{destination}}', NULL, NULL, NULL,
  'Asia/Jakarta', 1, 23
);
--> statement-breakpoint
INSERT OR IGNORE INTO product_packages (
  product_id, sku, label, price, note, provider_code, provider_sku, supplier_price,
  pricing_mode, margin_type, margin_value, is_active, sort_order
)
SELECT id, 'rf-7', 'Paket 7 Hari', 35000, NULL, 'voucher-stock', 'redfinger-7-hari', NULL,
       'manual', 'fixed', 0, 1, 0
FROM products
WHERE slug = 'redfinger';
--> statement-breakpoint
INSERT OR IGNORE INTO product_packages (
  product_id, sku, label, price, note, provider_code, provider_sku, supplier_price,
  pricing_mode, margin_type, margin_value, is_active, sort_order
)
SELECT id, 'rf-30', 'Paket 30 Hari', 110000, 'Populer', 'voucher-stock', 'redfinger-30-hari', NULL,
       'manual', 'fixed', 0, 1, 1
FROM products
WHERE slug = 'redfinger';
--> statement-breakpoint
INSERT OR IGNORE INTO product_packages (
  product_id, sku, label, price, note, provider_code, provider_sku, supplier_price,
  pricing_mode, margin_type, margin_value, is_active, sort_order
)
SELECT id, 'rf-90', 'Paket 90 Hari', 295000, NULL, 'voucher-stock', 'redfinger-90-hari', NULL,
       'manual', 'fixed', 0, 1, 2
FROM products
WHERE slug = 'redfinger';
--> statement-breakpoint
