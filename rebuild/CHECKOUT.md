# Checkout

Checkout adalah server-authoritative. Browser tidak dipercaya sebagai sumber cost, margin, discount, fee, total, provider mapping, external SKU, atau payment gateway.

## Flow

1. Load product/package dan field customer yang aktif.
2. Validasi customer input dan nickname bila checker tersedia/configured.
3. Pilih package/nominal.
4. Customer memilih payment channel publik.
5. Backend resolve provider mapping dan payment route.
6. Backend hitung membership + voucher + payment fee.
7. Customer mengonfirmasi ringkasan server.
8. Backend membuat order secara idempotent di MySQL transaction.
9. Order menyimpan snapshot dan masuk `PENDING_PAYMENT`.
10. Payment flow dilanjutkan untuk order yang sama.

## Idempotency

Checkout menerima idempotency key dan membuat fingerprint dari actor, package, channel, voucher, dan customer input. Key yang sama dengan fingerprint sama mengembalikan order yang sudah ada; key sama untuk checkout berbeda ditolak.

Unique constraint MySQL menjadi proteksi tambahan terhadap race.

## Pricing/snapshot

Di dalam transaction, backend mengunci/revalidasi package/provider mapping, customer input, membership, voucher, dan payment route lalu menghitung:

- provider cost;
- margin;
- membership discount;
- voucher discount;
- payment fee;
- final total.

Snapshot order menyimpan product/package/provider/payment/pricing/membership/voucher/customer input yang dipakai saat order dibuat. Harga order tidak berubah hanya karena setting katalog/provider berubah kemudian.

Voucher reservation dibuat secara atomic dan mengikuti quota/per-customer/scope/period/minimum rule.

## Payment routing boundary

Customer hanya mengirim `payment_channel_code`. Backend yang memilih gateway/route. Gateway internal tidak boleh dipilih atau dipaksa customer.

Guest tidak dapat memilih wallet. Channel unavailable/maintenance/not-ready tidak boleh menghasilkan route checkout.

Lihat [PAYMENT.md](PAYMENT.md) untuk state dan callback, serta [FULFILLMENT.md](FULFILLMENT.md) untuk provider attempt.
