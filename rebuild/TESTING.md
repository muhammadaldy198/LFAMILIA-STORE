# M11 Testing Gate

M11 is a regression milestone. It does not change checkout, payment, fulfillment, wallet, or Admin business rules unless a test exposes a defect.

## Required gates

- Full Laravel feature/API suite on MySQL 8 + Redis.
- Production frontend build.
- PHP syntax and Pint.
- Composer audit and repository secret guardrails.
- Migration apply / rollback / re-apply.
- Headless browser smoke against the built Inertia application.
- Critical abuse and state-machine regression coverage.

## PRD coverage matrix

| PRD risk / scope | Regression coverage |
|---|---|
| Functional customer flows | CatalogTest, CustomerAreaTest, GuestOrderTest, browser smoke |
| Authentication / RBAC | CustomerAuthTest, AdminAuthTest, AdminM9Test, SecurityM10Test |
| API authorization | CustomerAuthTest Sanctum coverage and protected account/Admin routes |
| Manipulated price / provider / SKU | CheckoutTest::test_client_price_provider_and_sku_are_rejected |
| Illegal Rp0 order | DatabaseSchemaTest::test_zero_total_order_is_rejected_by_mysql |
| Voucher double use / quota | CheckoutTest::test_voucher_is_reserved_atomically_and_quota_cannot_be_reused |
| Duplicate order | Checkout idempotency and idempotency-fingerprint conflict tests |
| Duplicate payment | PaymentTest payment-key and wallet idempotency tests |
| Insufficient / negative wallet | PaymentTest M11 insufficient-balance regression + wallet schema invariants |
| Fake / replayed payment callback | PaymentTest + SecurityM10Test |
| Late callback / final-state downgrade | PaymentTest expired/late and stale callback tests |
| Duplicate fulfillment | FulfillmentTest single-attempt and webhook idempotency tests |
| Provider timeout / unknown | FulfillmentTest timeout + reconciliation test |
| Unsafe failover | FulfillmentTest pending/unknown and confirmed-failure tests |
| Browser customer smoke | tests/Browser/smoke.sh: home, login, register, guest order lookup |
| Browser Admin smoke | tests/Browser/smoke.sh: Admin login |
| Secret leakage / hardcoded credentials | validate-rebuild security guardrails + SecurityM10Test |

## M11 boundary

Production DNS, Cloudflare, Nginx, SSL, queue workers, scheduler and backup deployment are M12. M11 validates the repository and application behavior before deployment.
