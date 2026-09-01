UPDATE product_packages
SET pricing_mode = 'auto', margin_type = COALESCE(margin_type, 'fixed'), margin_value = COALESCE(margin_value, 0)
WHERE provider_code = 'digiflazz';
