ALTER TABLE customer_users ADD COLUMN tier_mode TEXT DEFAULT 'automatic' NOT NULL;
--> statement-breakpoint
ALTER TABLE customer_users ADD COLUMN tier_override TEXT;
--> statement-breakpoint
ALTER TABLE customer_users ADD COLUMN tier_progress_bonus INTEGER DEFAULT 0 NOT NULL;
