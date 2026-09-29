# Fulfillment (M8)

M8 consumes only orders whose payment state is already verified as PAID. It adds automatic Digiflazz fulfillment, manual fulfillment, queue recovery, webhook handling, retry/reconciliation and safe failover. M9 still owns the full Integration Manager, broader Admin permissions and notification channels.

## Non-negotiable state rules

- Payment redirect never starts fulfillment.
- A newly verified PAID order queues exactly one StartFulfillmentJob.
- Database row locks and the unique (order_id, provider_mapping_id) constraint are the source-of-truth anti-duplicate controls; unique queue jobs reduce redundant work.
- PENDING, UNKNOWN and stale SENDING attempts are never failed over. They are reconciled with the same provider reference.
- Only FAILED_CONFIRMED can authorize failover.
- A successful order never returns to PROCESSING because of an old provider callback.
- A contradictory late provider response after FAILED_CONFIRMED is logged as a conflict and does not silently reopen the old attempt.

## Digiflazz adapter

Credential source: encrypted integration_credentials row with code digiflazz.

Expected encrypted fields:
- username
- api_key
- webhook_secret
- optional base_url (defaults to https://api.digiflazz.com)
- optional testing
- optional callback_url

No credential value is stored in GitHub or returned to customer APIs.

The adapter calls /v1/transaction using:
- buyer_sku_code from the server-side provider mapping;
- customer_no generated server-side from the order input snapshot;
- an LFAMILIA-generated unique ref_id;
- sign = md5(username + api_key + ref_id);
- max_price from the immutable order guard.

When Digiflazz returns Pending, reconciliation repeats the transaction using the same ref_id. Transport errors, 5xx responses or malformed responses become UNKNOWN instead of creating a second transaction.

## customer_no mapping

provider_mappings.fulfillment_config may contain:

```json
{
  "customer_no_template": "{{user_id}}{{zone_id}}"
}
```

Placeholders must reference configured product input fields. If a product has only one non-empty customer input, the template may be omitted and that value is used directly. Multi-field products require an explicit template before the mapping can be activated from the catalog panel.

## Price guard

M8 never trusts a provider price supplied by the customer. The request max_price is frozen from:
1. snapshot.provider.max_price_idr when positive; otherwise
2. snapshot.provider.cost_idr.

Before a provider request is sent, the current mapping cost must still be within this frozen max_price. If it exceeds the order guard, fulfillment is BLOCKED before any external request.

A failover candidate must also be active, unused for the order, automatic, supported by an implemented adapter, and have cost <= the original order max_price. The customer total and financial snapshot never change during failover.

## Attempt states

- CREATED: local attempt exists but has not been sent.
- SENDING: request preparation completed and the worker is sending/checking the provider reference.
- PENDING: provider confirms the transaction is still pending.
- UNKNOWN: request/result cannot be proven; only reconciliation with the same ref_id is allowed.
- SUCCESS: fulfillment completed.
- FAILED_CONFIRMED: provider explicitly returned a final failure; failover may be considered.
- BLOCKED: LFAMILIA proved no provider request was sent because local configuration/preflight failed; the same attempt can be retried safely after correction.
- MANUAL_PENDING: paid manual order is waiting for Admin/Super Admin.
- MANUAL_FAILED: manual fulfillment was explicitly failed.

## Digiflazz webhook

Endpoint:

`POST /api/fulfillment/digiflazz/webhook`

Requirements:
- X-Digiflazz-Event must be create or update.
- User-Agent must be Digiflazz-Hookshot for the prepaid flow implemented in M8.
- X-Hub-Signature is verified as SHA-1 HMAC over the raw body with webhook_secret.
- ref_id, buyer_sku_code and customer_no must match the stored request payload.
- identical callbacks are deduplicated in fulfillment_callbacks.

The customer never sees provider code, provider SKU, provider cost, request signature, provider balance, webhook secret or internal response payload.

## Recovery scheduler

`lfamilia:recover-fulfillment`:
- queues paid orders that never obtained a fulfillment attempt;
- queues stale CREATED attempts for send;
- queues stale PENDING / UNKNOWN / SENDING attempts for reconciliation.

It is scheduled every minute with overlap protection. Production queue workers and the Laravel scheduler are deployed in M12.

## Manual fulfillment

Paid MANUAL orders create MANUAL_PENDING attempts and move to PROCESSING. The M8 Admin fulfillment workspace allows Admin/Super Admin to:
- view customer input and internal instructions;
- mark the attempt successful with an optional delivery code and customer note;
- mark it failed with a required reason;
- retry BLOCKED automatic attempts;
- request safe failover only from FAILED_CONFIRMED attempts.

Only customer-safe delivery data is copied to orders.delivery_payload and exposed on authenticated/guest order status pages.

## M9 boundary

M8 consumes encrypted Digiflazz credentials but does not build the full credential editor, provider dashboard, Telegram/Discord notifications, granular Admin permissions or System Health UI. Those remain M9.
