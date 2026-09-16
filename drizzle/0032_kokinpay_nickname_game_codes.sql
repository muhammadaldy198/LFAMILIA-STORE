-- Product-specific Kokinpay game code. NULL keeps nickname validation disabled for that product.
ALTER TABLE products ADD COLUMN nickname_game_code TEXT;
