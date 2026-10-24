# Verified store facts — starting point for any PRD rewrite

Everything here was read out of the repo or the live site. Re-verify the ones marked
(volatile) before quoting them; the rest move only when the schema moves.

## Order statuses

- Internal vocabulary: `AdminOrderPresentation::STATUSES` — 18 codes
  (`PENDING_PAYMENT`, `PAID`, `PROCESSING`, `SUCCESS`, `FAILED`, `EXPIRED`, `CANCELLED`,
  `REFUND`, `CREATING`, `PENDING`, `REFUNDED`, `CREATED`, `SENDING`, `UNKNOWN`,
  `FAILED_CONFIRMED`, `BLOCKED`, `MANUAL_PENDING`, `MANUAL_FAILED`).
- Customer-facing vocabulary: six — Menunggu Pembayaran, Sedang Diproses, Berhasil,
  Gagal, Kedaluwarsa, Dibatalkan (matches `/faq`).
- `REFUND` is labelled "Perlu pemeriksaan admin" — a review state, not an executed refund.
- Cancellation is allowed only from `PAID`, `FAILED`, `BLOCKED` (`AdminOrderCancellationService`).

## Refund (from the live `/refund` page, updated 31 Aug 2026)

Eligible: verified payment but final failure; duplicate payment; product unavailable;
under-delivered nominal; fraudulent payment reported before delivery.

Not eligible: already delivered; wrong game/product/nominal/User ID/server/region/contact;
code already shown or used; account blocked by publisher; publisher bonus not received;
change of mind or a short delay still within normal handling.

Windows: file within 7 calendar days; review 1–7 business days; funds up to 14 business days.
Settlement: members → Super Admin wallet adjustment (mandatory reason, ledger + audit);
guests → manual transfer outside the system (no wallet). No automatic refund path anywhere.

## Payment channels

- Gateways seeded: `MIDTRANS` (Snap), `DOKU` (**inactive; the owner intends to install it
  later — keep the code, keep it hidden, do not call it dead**), `MANUAL_QRIS`, `WALLET`.
- Channels: `qris`, `virtual_account`, `ewallet`, `manual_qris`, `saldo`.
- Midtrans notification: `sha512(order_id + status_code + gross_amount + ServerKey)`;
  paid = `settlement`, or `capture` with `fraud_status=accept`. Redirect is never proof.
- Midtrans methods actually enabled: QRIS, GoPay, BNI/BRI/CIMB/Permata/BSI VA, Mandiri
  echannel. BCA, OVO, DANA, ShopeePay are not confirmed — see `lfamilia-payment-guardian`.

## Wallet

- `wallet.topup_enabled` seeded `true`; `wallet.minimum_topup_idr` configurable.
- Ledger is append-only: before/after balance, source, reference, actor, idempotency key.
- Guests have no wallet. Non-negative balance is an invariant.
- Wallet adjustment (the member-side settlement of a manual refund) is **Super Admin only**,
  needs a mandatory reason, and has **no per-transaction nominal cap** — the role, the reason
  and the audit trail are the guard. Name it "penyesuaian saldo", never "refund".

## Digiflazz contract (official docs)

- `POST https://api.digiflazz.com/v1/transaction`;
  `sign = md5(username + apiKey + ref_id)`; response is synchronous (sukses/gagal/pending).
- Pending is re-checked by re-sending the **same `ref_id`**; never more often than once per
  minute; **never re-check a transaction older than 90 days** (it creates a new one).
- Price list: `cmd=prepaid`, `sign = md5(username + apiKey + "pricelist")`, cached locally;
  data lags 10–15 minutes, so never query it per visitor.
- Webhook: JSON POST, `X-Hub-Signature: sha1=HMAC-SHA1(body, secret)`, `X-Digiflazz-Event`
  (`create` / `update`), payload carries `ref_id`, `status`, `rc`, `sn`, `price`,
  `buyer_last_saldo`.
- In-repo behaviour: automatic re-checks stop at 3 attempts, 2 minutes apart; a confirmed
  failure blocks the order instead of failing over to another SKU.

## Settings keys that decide product behaviour

`wallet.topup_enabled`, `wallet.minimum_topup_idr`, `digiflazz.low_stock_threshold` (default
5 transactions), `digiflazz.auto_sync`, `digiflazz.price_warning_percent`,
`store.support_whatsapp`, `store.instagram_url`, `store.discord_url`, `support.quick_replies`.

`store.discord_url` is empty in the local copy while the live footer already links
`discord.gg/…` — the footer has **four** channels live (Instagram, WhatsApp, Email, Discord).
The empty one that matters is the admin **notification** webhook, a different setting.

## Roles and permissions

`AdminPermissionService::DEFINITIONS` — dashboard.view, orders.view, catalog.manage,
content.manage, fulfillment.manage, providers.manage, payments.manage, customers.view,
vouchers.manage, support.manage, reports.view, reports.finance, settings.manage,
notifications.view. Super-only: Validasi Akun, Admin & Akses, Integrasi, System Health,
Audit Log.

## Membership

Six tiers seeded: BASIC, SILVER, GOLD, DIAMOND, PLATINUM, MAFIA (ranked 1–6). Tier is a
customer benefit, never an admin permission.

Owner-set spend thresholds (editable per tier in the panel; the order never changes):
BASIC 0 (new account) · SILVER 500.000 · GOLD 5.000.000 · DIAMOND 20.000.000 ·
PLATINUM 50.000.000. Progress counts paid orders (`PAID`/`PROCESSING`/`SUCCESS`).

**MAFIA is internal**: it is the owner's own customer-side account, never sold, never shown
on the customer tier page, never offered as a "next tier" target. A customer-side admin
account and a panel admin account are two separate systems — never merge them by email.
Discount percentages per tier are intentionally left unset and edited from the panel.

## Public pages that must survive a redesign

`/`, `/catalog`, `/catalog/{slug}`, `/checkout`, `/track`, `/news`, `/news/{slug}`, `/faq`,
`/contact`, `/support`, `/terms`, `/privacy`, `/refund`, `/promo`, `/leaderboard`, `/tools`,
`/status`, `/payment`. Note the Indonesian-language URLs used in navigation differ from the
route paths — check `routes/web.php` before quoting a URL.

## Site state

Development preview with a public URL. No real buyers, no real orders, wallet never used.
Any spec or panel screen must say "belum dipakai" instead of showing sample numbers.

## Operational limits the owner set

- **Manual orders:** a warning fires after **1 hour inside CS service hours**; the clock
  stops outside them. With nobody on duty the manual product is deactivated instead.
- **Guest data retention:** **30 days** since last activity, then the scheduled cleanup
  removes it (transaction records needed for bookkeeping survive).
- **Day boundary:** 00:00–23:59 WIB for dashboard and every report, so both agree.
- **Low provider balance:** keep the default of 5 remaining transactions.
