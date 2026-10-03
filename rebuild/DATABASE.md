# Database

MySQL 8 adalah database bisnis runtime LFAMILIA STORE. Schema dikelola melalui Laravel migrations di `database/migrations/`. D1 dan MariaDB bukan database final/current.

## Domain tabel penting

Identity/catalog:

- `users`, `admin_users`, `membership_tiers`
- `categories`, `products`, `product_packages`, `product_input_fields`
- `providers`, `provider_mappings`
- Spatie `media` dan store/content tables terkait

Commerce:

- `orders`
- `vouchers`, scope tables, `voucher_redemptions`
- `wallets`, `wallet_ledger`, `wallet_topups`
- `payment_gateways`, `payment_channels`, `payment_routes`
- `payment_transactions`, `payment_callbacks`
- `fulfillment_attempts`
- `order_events`

Operational/admin tables lain ditambahkan migrations berikutnya untuk support, audit, notification, integration settings, content, dan workspace Admin.

Daftar authoritative selalu migration aktual, bukan daftar di Markdown.

## Transaction safety

Schema/service menggunakan kombinasi:

- unique idempotency keys pada order/top-up/payment/ledger flows;
- request fingerprints untuk mendeteksi reuse key pada request berbeda;
- unique merchant/external/provider references;
- callback event uniqueness;
- MySQL check constraints untuk nominal/target yang valid;
- foreign keys dan restricted/cascade delete sesuai domain;
- `lockForUpdate` pada state transition kritis.

## Order/payment relationship

Order menyimpan customer/product/package/provider mapping, voucher, payment channel/route, state, customer input, financial fields, immutable snapshot, idempotency key, dan timestamps.

`payment_transactions` menunjuk tepat satu target: order atau wallet top-up. Payment menyimpan route/gateway/channel, amount, state, merchant/external reference, request fingerprint, payload yang diperlukan, callback timing, dan idempotency key.

`payment_callbacks` menyimpan provider event identity/hash/result untuk deduplication/audit tanpa menjadikan raw secret sebagai data dokumentasi.

## Wallet

`wallets` menyimpan balance/version. `wallet_ledger` adalah record mutation dengan amount, before/after balance, source, reference, actor, timestamp, dan unique idempotency key.

Debit/credit dilakukan melalui transaction dan row lock di service; saldo negatif tidak boleh dibuat.

## Fulfillment/audit

`fulfillment_attempts` menyimpan provider mapping, external reference, status, correlation/reconciliation state dan metadata transaksi yang dibutuhkan.

`order_events` merekam event, from/to status, correlation ID, metadata, dan timestamp.

Audit Admin/security menggunakan audit tables/services terkait; jangan mengandalkan application log sebagai satu-satunya audit trail.

## Data safety

Jangan commit:

- production SQL dump;
- PII export;
- payment token/raw secret;
- integration ciphertext dump;
- DB credentials;
- backup encryption material.

Backup production dibuat di server oleh deployment tooling dan dienkripsi; lihat [deploy/README.md](deploy/README.md).
