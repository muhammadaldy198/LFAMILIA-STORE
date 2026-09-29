# Database v1 (M2)

This schema belongs to the isolated `rebuild/` Laravel runtime. MySQL 8 is the single business database. No D1/MariaDB data is imported by these migrations.

## Conventions

- Every `*_idr` amount is an integer number of **Rupiah**, matching IDR payment amounts. There is no implicit division or multiplication by 100.
- Customer accounts are in `users`; `admin_users` are separate. BASIC–MAFIA is customer membership, never an admin permission.
- Guest orders have a null `user_id`. Wallets require an existing user and are unique per user.
- Categories → products → product_packages → provider_mappings. Provider credentials are not stored in catalog tables. DIGIFLAZZ and MANUAL are seeded inactive.
- Order input and commercial terms are snapshotted on `orders`; future checkout code must compute the amount on the server and never accept a client total.
- `wallet_ledger` uses signed movement, before/after balance, a unique idempotency key and a source/reference pair. The database checks arithmetic. Future wallet code must lock the wallet row and append ledger and balance changes in the **same transaction**. No ordinary update/delete API for ledger entries.
- `payment_transactions` target exactly one order or wallet top-up. Verified callbacks and fulfillment attempts have unique references. Payment state transition and reconciliation logic belong to M7/M8.
- Voucher scopes and reservations are stored separately; the capacity and per-customer checks must be transactional in M6.
- `audit_logs` carry actor, before/after, IP, user agent and correlation ID. Sensitive values must be redacted before writing; `system_settings` must never contain plaintext integration credentials.

## Verification

The rebuild CI migrates MySQL 8, rolls all M2 migrations back, migrates again, runs schema invariant tests, builds Vue, and checks PHP style. These migrations define data shape and constraints; they do not activate customer checkout, payments, or provider calls.
