# Payment, routing & wallet safety

## Gateway/channel model

Customer memilih **payment channel** publik. Gateway internal dipilih backend.

Implementasi mempunyai gateway registry untuk Midtrans, DOKU, Manual QRIS, dan LFAMILIA Wallet. Dukungan kode tidak berarti gateway eksternal sudah live-configured.

`PaymentRoutingService` hanya memilih route yang:

- channel aktif;
- route aktif;
- gateway aktif;
- gateway tidak maintenance;
- mendukung purpose `order` atau `topup`;
- gateway eksternal mempunyai credential readiness yang diperlukan.

Candidate diurutkan dengan `payment_routes.priority` kecil lebih dahulu, lalu ID. Priority 10 didahulukan dari 20.

**Route selection sebelum external request bukan failover sesudah request.** Jika request eksternal sudah diklaim/dikirim dan hasilnya tidak pasti, transaksi dapat masuk `UNKNOWN`. Jangan membuat payment kedua sebagai recovery otomatis.

## Payment creation idempotency

Order dan wallet top-up memakai payment idempotency key + request fingerprint. Key untuk request yang berbeda ditolak. Untuk satu order/top-up, service juga mencari existing non-rejected payment agar double-create tidak terjadi.

Lifecycle internal creation mencakup `CREATING` dan `SENDING`; public response menyembunyikan detail internal yang tidak diperlukan.

## State machine

`PaymentStateService` menerima status provider tervalidasi: `PENDING`, `PAID`, `FAILED`, `CANCELLED`, `EXPIRED`, `REFUNDED`.

Proteksi final/stale antara lain:

- `REFUNDED` tidak dibuka kembali;
- `PAID` tidak didowngrade selain flow refund;
- payment yang sudah FAILED/CANCELLED/EXPIRED tidak dikembalikan ke PENDING oleh callback lama;
- late PAID setelah order terminal tidak otomatis memproses ulang order; masuk review path;
- amount callback harus cocok dengan amount lokal.

Redirect browser bukan bukti paid.

## Callback authority

Midtrans dan DOKU webhook handler melakukan verifikasi yang diimplementasikan sebelum memanggil state transition. Callback dicatat dengan event/payload hash dan unique provider event identity untuk idempotency/replay protection.

Detail signature/challenge ada di [SECURITY.md](SECURITY.md).

## Wallet

Pembayaran order dengan wallet dan credit/refund top-up memakai MySQL transaction + `lockForUpdate` pada payment/order/topup/wallet yang relevan.

Wallet ledger menyimpan amount, balance before/after, source, reference, actor, timestamp, dan idempotency key. Ledger idempotency mencegah credit/debit yang sama diterapkan dua kali.

Saldo tidak boleh menjadi negatif. Refund top-up yang tidak dapat langsung direverse karena saldo sudah terpakai masuk `REFUND_REVIEW`, bukan memaksa saldo negatif.

## Manual QRIS

Manual QRIS hanya tersedia bila gateway/channel/asset terkait aktif. Create payment menampilkan asset QRIS terkonfigurasi dan order tetap menunggu verifikasi.

Konfirmasi pembayaran dilakukan melalui action Admin yang diizinkan dan diaudit. Konfirmasi hanya mengubah payment/order ke paid; fulfillment tetap memakai flow fulfillment yang sama.

## Gateway credential

Provider/payment secret dikelola melalui Super Admin → Integrasi dan disimpan terenkripsi. Jangan hardcode server key/client secret di route config atau Markdown.
