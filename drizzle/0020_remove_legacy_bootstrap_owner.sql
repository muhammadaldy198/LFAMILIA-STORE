-- Remove the untouched legacy bootstrap owner inserted by 0003.
-- Configured owners are preserved because their panel credential exists under
-- the internal __lfadmin__: namespace, and owners whose login ID was changed
-- no longer match the legacy email.
DELETE FROM admin_users
WHERE lower(email) = 'muhammadaldy198@gmail.com'
  AND role = 'owner'
  AND name = 'Pemilik LFAMILIA'
  AND NOT EXISTS (
    SELECT 1
    FROM customer_users
    WHERE email = ('__lfadmin__:' || lower(admin_users.email))
  );
