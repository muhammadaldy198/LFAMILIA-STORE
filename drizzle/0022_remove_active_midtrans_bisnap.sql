-- BI-SNAP is no longer an active LFAMILIA payment mode.
-- Preserve historical order columns, but remove obsolete active integration configuration.
DELETE FROM integration_profiles
WHERE provider = 'midtrans' AND mode = 'bisnap';

INSERT INTO integration_settings (setting_key, value, updated_at)
VALUES ('midtrans_mode', 'snap', CURRENT_TIMESTAMP)
ON CONFLICT(setting_key) DO UPDATE SET
  value = 'snap',
  updated_at = CURRENT_TIMESTAMP;
