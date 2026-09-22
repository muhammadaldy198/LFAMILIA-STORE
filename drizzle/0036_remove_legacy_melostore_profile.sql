-- Melostore is no longer a supported runtime integration. Remove its legacy
-- encrypted profile without touching active provider configurations.
DELETE FROM integration_profiles
WHERE provider = 'melostore';
