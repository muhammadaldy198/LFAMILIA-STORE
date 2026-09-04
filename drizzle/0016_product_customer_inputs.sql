ALTER TABLE products ADD COLUMN input_fields_json TEXT;
--> statement-breakpoint
ALTER TABLE orders ADD COLUMN customer_inputs_json TEXT DEFAULT '[]' NOT NULL;
