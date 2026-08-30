CREATE TABLE IF NOT EXISTS `media_assets` (
  `media_key` text PRIMARY KEY NOT NULL,
  `content_type` text NOT NULL,
  `data` blob NOT NULL,
  `etag` text NOT NULL,
  `original_name` text,
  `created_at` text DEFAULT CURRENT_TIMESTAMP NOT NULL
);
--> statement-breakpoint
CREATE INDEX IF NOT EXISTS `media_assets_created_at_idx`
ON `media_assets` (`created_at`);
