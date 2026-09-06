-- Midtrans integration removed from LFAMILIA runtime.
-- Historical schema columns are intentionally left untouched for safe compatibility
-- with databases where older migrations/runtime repairs have already run.
DELETE FROM integration_profiles WHERE provider = 'midtrans';
DELETE FROM integration_settings WHERE setting_key IN ('midtrans_mode', 'midtrans_environment');
