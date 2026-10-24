# Digiflazz Specialist — Worked Rules + Verification Checklist

Sources: `AGENTS.md` §3, §6; `CLAUDE.md` §9, §11, §12;
`rebuild/FULFILLMENT.md`; `rebuild/PRODUCT.md`; owner instructions
(checkout cut-off design). All numbers below were verified by direct
computation. Currency is IDR (rupiah).

## 1. Seller selection

- Eligible sellers: rating ≥ 4.8 ONLY.
- Among eligible sellers: pick the CHEAPEST.
- No seller ≥ 4.8 → DELETE the product (do not keep it with a worse
  seller).
- `Invalid Seller` rows MUST be worked (do not leave them).

Worked example (simulated supplier data):

| Seller | Price | Rating | Eligible? |
|---|---|---|---|
| Seller A | 10.500 | 4.9 | Yes |
| Seller B | 9.900 | 4.7 | No (rating < 4.8) |
| Seller C | 10.000 | 4.8 | Yes |
| Seller D | 9.800 | 5.0 | Yes |

Winner: **Seller D** (cheapest among eligible: 9.800, rating 5.0).
Seller B is cheaper than A and C but is NOT eligible (4.7 < 4.8).
If all sellers were below 4.8, the product is deleted.

## 2. Catalog Harga Max (Buyer Area / sync)

Formula: **Harga Max = seller price × 1.02, rounded to the nearest
rupiah.** This is the CATALOG / Buyer Area Harga Max, not the checkout
snapshot limit (see §4).

Verified examples:

| Seller price | × 1.02 | Harga Max (nearest rupiah) |
|---|---|---|
| 10.000 | 10.200 | 10.200 |
| 9.850 | 10.047 | 10.047 |
| 9.876 | 10.073,52 | 10.074 |

## 3. Sell price (ML only)

- **Cost (`cost_idr`) = Harga Max Digiflazz (fixed, not the fluctuating
  seller price).**
- **ML sell price = cost × 1.06, NOT rounded.**

Verified examples (cost = Harga Max from §2):

| Cost (Harga Max) | × 1.06 | ML sell (NOT rounded) |
|---|---|---|
| 10.200 | 10.812 | 10.812 |
| 10.047 | 10.649,82 | 10.649,82 |
| 10.074 | 10.678,44 | 10.678,44 |

**Other games' sell prices are NOT decided.** If a task needs one and
no owner decision exists, say "tanya pemilik" / "tidak ditentukan" —
never invent a margin, formula, or price.

## 4. Order snapshot max_price (transaction limit) — NOT the same as §2

Formula: **snapshot max_price = highest modal of active + available
Digiflazz SKUs in the SAME nominal + 1%, rounded UP.**
Stored as an order snapshot; sent as `max_price` on every Digiflazz
attempt and reconciliation.

Verified examples:

| Highest modal (same nominal) | + 1% | Snapshot (rounded UP) |
|---|---|---|
| 10.000 | 10.100 | 10.100 |
| 10.047 | 10.147,47 | 10.148 |

Rules:

- Inactive / unavailable SKUs do NOT enlarge the limit.
- Sell price is still computed from the primary SKU and is NOT
  auto-raised when failover uses a more expensive seller.
- A SKU whose modal exceeds the snapshot limit must NOT be selected.
- Do NOT confuse `provider_mappings.max_price_idr` (administrative
  per-source limit at sync) with
  `order.snapshot.provider.max_price_idr` (transaction limit from all
  candidates at checkout).

## 5. Cut-off / availability

- A SKU whose modal (cost) exceeds Harga Max, or whose seller is
  offline, is hidden (**"Tidak tersedia"**). Intended behaviour, not a
  bug. Do not "fix" it by showing the SKU.
- Before an external request, a mapping may be declared unusable and
  skipped when: mapping/provider inactive; adapter unsupported;
  external SKU empty; Digiflazz catalog item inactive / out of stock /
  cut-off; voucher-stock key/stock unavailable; provider cost invalid;
  cost exceeds the order's `max_price` snapshot.

## 6. Fulfillment candidate order + safety

Candidate order: (1) smaller `priority` first; (2) smaller cost;
(3) mapping ID.

- `PENDING` / `SENDING` / `UNKNOWN` must NOT trigger an immediate
  next-source fulfillment.
- Reconcile using the SAME stored attempt/reference. Verify against
  stored `ref_id`, `buyer_sku_code`, `customer_no`.
- Next source ONLY after the previous path is definitively safe for
  failover (e.g. `FAILED_CONFIRMED` + safe-to-failover condition, or a
  candidate safely skipped before any external request). Attempted
  mapping IDs are excluded.
- NO DOUBLE FULFILLMENT. Do not retry the same attempted mapping
  blindly. Do NOT rewrite the order snapshot cost/total when a later
  mapping is selected.
- A network timeout does NOT prove the provider failed. Never create a
  second action merely because the first request timed out.

## 6b. Reading the official docs offline

The buyer docs are an mkdocs site, so the whole corpus is one JSON file — far faster and more
reliable than paging through rendered pages:

```bash
curl -sL -m 30 https://developer.digiflazz.com/search/search_index.json -o /tmp/dfz.json
python3 - <<'EOF'
import json
d = {x['location']: x for x in json.load(open('/tmp/dfz.json'))['docs']}
for loc in ['api/buyer/topup/#deskripsi', 'api/buyer/webhook/#list-headers',
            'api/buyer/cek-status/', 'api/buyer/daftar-harga/#deskripsi']:
    print('='*8, loc); print(d.get(loc, {}).get('text', '')[:900])
EOF
```

`docs[].location` is the page path plus `#anchor`, and `docs[].text` is the rendered body —
parameter tables, signatures, and the warnings (1-minute floor, 90-day trap) all come through.
Grep the loaded dict for a keyword rather than guessing page names.

Midtrans is the same shape: append `.md` to a docs URL
(`https://docs.midtrans.com/docs/https-notification-webhooks.md`) to get the raw markdown,
including the transaction-status table and the signature formula.

## 7. Verification checklist

- [ ] Seller picked is the cheapest among rating ≥ 4.8 (or product
      deleted if none qualifies).
- [ ] Harga Max computed as seller × 1.02, nearest rupiah (verified
      arithmetic, not guessed).
- [ ] Cost = Harga Max (fixed); ML sell = cost × 1.06, NOT rounded
      (verified arithmetic).
- [ ] Non-ML sell price: owner decision exists, or task says
      "tanya pemilik" (never invented).
- [ ] Catalog Harga Max (§2) NOT confused with snapshot max_price (§4).
- [ ] Cut-off SKUs hidden ("Tidak tersedia"), not shown.
- [ ] `Invalid Seller` rows worked; rows without SKU got a new SKU
      (model `_A`); products saved one by one; all products ON.
- [ ] Fulfillment candidate order correct (priority → cost → ID).
- [ ] No double fulfillment path exists in the change.
- [ ] No real Digiflazz transaction sent without explicit authorization
      for that exact test.
- [ ] No manual prices / catalog / DB / order data changed during
      deploy. No real customer data used as fixtures.
