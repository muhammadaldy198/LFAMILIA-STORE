# Authentication (M3)

- Customer web sessions use Fortify's `web` guard. Register, login, logout, email verification, forgotten password, and password reset have Inertia pages. Passwords require at least 12 characters. Google accounts without local passwords cannot log in through the password form.
- Admin and Super Admin use a separate `admin` session guard and `admin_users` table. Inactive accounts and roles outside SUPER_ADMIN/ADMIN are denied. The session lifetime is 1,440 minutes. No Staff role is created.
- The first Super Admin is created interactively with `php artisan lfamilia:bootstrap-super-admin`; no bootstrap password or provider secret exists in Git. Run it only on a prepared installation.
- Sanctum protects `/api/account` for the future Android API. Token issuance policy for Android is deferred until the Android phase; customer web sessions use CSRF-protected routes.
- Google OAuth uses Socialite's stateful redirect/callback. Client ID and secret are read from `integration_credentials.config_ciphertext` using Laravel encrypted casts; the callback URL is generated from the app URL. The integration is disabled until Super Admin → Integrasi (M9) writes valid credentials. A verified Google email can link an existing customer email; a conflicting Google subject is rejected. A new Google customer must provide a phone before accessing the account. OTP is not required by the v1 PRD.
- Password reset and verification notifications use Laravel's configured mail transport. Production Resend configuration and panel control belong to M9; no production email credential is in this milestone.
- Customer account/profile/wallet UI, admin operations, and provider integrations belong to later milestones. The M3 pages expose only functional auth/account boundaries.
