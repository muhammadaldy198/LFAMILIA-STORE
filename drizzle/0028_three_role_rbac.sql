-- Keep all existing users and transactions; only normalize legacy panel-role labels.
UPDATE admin_users SET role = 'super_admin' WHERE role = 'owner';
UPDATE admin_users SET role = 'staff' WHERE role IS NULL OR TRIM(role) = '';
CREATE INDEX IF NOT EXISTS `admin_users_role_active_idx` ON `admin_users` (`role`, `is_active`);
