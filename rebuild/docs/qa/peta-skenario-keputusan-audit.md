# PETA SKENARIO — fix/keputusan-audit-bc387476

Gerbang wajib skill `local-skenario-lanjutan`. Tiap butir: jalur bahagia, jalur gagal, dan "tetangga" (apa yang bisa rusak di sekitarnya).

## 1. S-02 — tutup REFUND_REVIEW tanpa gerakkan uang
| Skenario | Harapan |
|---|---|
| Top up PAID lalu gateway kirim REFUNDED | status `REFUND_REVIEW`, saldo **tidak** berubah, notifikasi admin |
| Admin klik "Selesaikan tinjauan" + alasan | status top up → `REFUNDED`, **saldo tetap**, audit `wallet.topup.refund_review_closed` |
| Alasan kosong | 422, tidak ada perubahan |
| Top up bukan REFUND_REVIEW | 422 "tidak sedang menunggu tinjauan" |
| Klik dua kali | idempoten: kedua kali 422, saldo tetap, satu audit |
| Setelah ditutup, member bayar pakai saldo | **berhasil** (kunci di `PaymentStateService:108` tidak lagi memblokir) |
| Admin tanpa izin `payments.manage` | 403 |
| `resolveTopupRefundReview` masih ada? | **tidak** — method + docblocknya hilang, `grep` kosong |
| Penyesuaian saldo manual (jalur uang sebenarnya) | tidak berubah perilakunya |

## 2. S-07 — backfill store_cost_idr
| Skenario | Harapan |
|---|---|
| Baris lama `store_cost_idr NULL` + `seller_reference_price_idr` ada | diisi `round(ref * 1.02)`, output jumlah baris |
| `seller_reference_price_idr` NULL tapi `cost_idr` ada | pakai `cost_idr` × 1,02 |
| Dua-duanya NULL | dilewati, tidak error, dihitung terpisah di laporan |
| Baris yang sudah punya `store_cost_idr` | **tidak disentuh** |
| Mapping MANUAL dan VOUCHER_STOCK tanpa `store_cost_idr` | **tidak disentuh**; backfill hanya berlaku untuk provider DIGIFLAZZ |
| Dijalankan dua kali | kedua kali 0 baris (idempoten karena syarat `whereNull`) |
| Setelah backfill, sync jalan | `max_price_idr` **tidak lagi** ditimpa (`$mapping->store_cost_idr === null` tidak terpenuhi) |
| Harga jual sebelum vs sesudah backfill | tidak berubah untuk baris yang modalnya = harga seller (uji) |

## 3. B-02 — ConnectionException
| Skenario | Harapan |
|---|---|
| Midtrans timeout (`ConnectionException`) | status `UNKNOWN`, pesan "jangan ulangi pembayaran", notifikasi admin, **HTTP bukan 500** |
| Timeout terjadi dua kali | transaksi tetap UNKNOWN, tidak jadi CREATING lagi |
| `ValidationException` (gateway menolak) | tetap `REJECTED` seperti sekarang |
| `RuntimeException` | tetap `UNKNOWN` seperti sekarang |
| Sukses normal | tidak berubah |

## 4. S-01 — alasan cepat pembatalan
| Skenario | Harapan |
|---|---|
| Pilih "Stok habis" → batal | audit `order.cancelled` reason = "Stok habis" |
| Pilih "Lainnya" tanpa isi teks | 422 (lainnya wajib dijelaskan) |
| Pilih "Lainnya" + teks | reason = teks bebas |
| Kirim tanpa alasan (API langsung) | 422 — alasan wajib |
| Alasan di luar daftar | 422 |
| `confirmed` tidak dikirim | 422 (tetap wajib) |
| Pesanan SUCCESS | tetap ditolak batal |

## 5. S-04 — peringatan antrean manual 60 menit
| Skenario | Harapan |
|---|---|
| MANUAL_PENDING 10 menit | `is_overdue=false` |
| MANUAL_PENDING 90 menit dalam jam layanan | `is_overdue=true`, muncul di kartu dashboard |
| 90 menit tapi di luar jam layanan | **false** — hitungan berhenti di luar jam |
| Produk tanpa jam layanan | dihitung 24 jam |
| Zona waktu WITA/WIT | jam dihitung di zona produk |
| Status bukan MANUAL_PENDING | tidak dihitung |
| Lebih dari 1.000 antrean manual | semua antrean dihitung lewat batch terbatas, tanpa batas hasil 1.000 |
| Pesanan sudah CANCELLED | tidak dihitung |

## 6. S-05 — bersihkan data guest 30 hari
| Skenario | Harapan |
|---|---|
| Order guest 31 hari | `guest_email`/`guest_phone`/`guest_phone_normalized` → NULL, order tetap ada |
| Order guest 29 hari | tidak disentuh |
| Order member (`user_id` tidak null) | tidak disentuh |
| Dijalankan dua kali | kedua kali 0 (sudah NULL) |
| `total_idr`, `order_number`, status | **tetap** (catatan transaksi) |
| Terjadwal harian | ada di `routes/console.php` |

## 7. B-04 — idempotensi per pelanggan
| Skenario | Harapan |
|---|---|
| Kunci sama, pelanggan sama, dua kali | kedua kali tidak menambah saldo, pesan "sudah pernah diproses" |
| Kunci sama, pelanggan **berbeda** | **diizinkan**, saldo B bertambah tepat satu kali; ledger tetap unik lewat kunci yang diberi lingkup pelanggan |
| Kunci raw lama, pelanggan sama | replay tidak menggerakkan saldo; tidak mencatat transaksi baru |
| Kunci beda, pelanggan sama | diproses sebagai penyesuaian baru |
| Kunci baru | normal |
| Nominal 0 | 422 (tetap) |

## 8. B-01 — batas kirim ulang per email
| Skenario | Harapan |
|---|---|
| 1× per menit per email | permintaan ke-2 dalam 60 detik ditolak halus |
| 3× per jam per email | permintaan ke-4 ditolak halus |
| Email berbeda, IP sama | tidak saling memblokir (batas per email) |
| Respons saat ditolak | **sama** dengan saat diterima (anti-enumerasi) |
| Email tak terdaftar | tidak menghabiskan kuota? — tetap dihitung supaya tidak bisa dipakai menebak |
| Batas per IP | tetap 6/menit |

## 9. B-05 — sukses manual wajib bukti
| Skenario | Harapan |
|---|---|
| Kode pengiriman diisi | sukses |
| Catatan diisi | sukses |
| Dua-duanya kosong | **422**, status tetap MANUAL_PENDING |
| Hanya spasi | dianggap kosong → 422 |

## 10. Voucher kembali saat dibatalkan
| Skenario | Harapan |
|---|---|
| Order PAID dengan voucher, dibatalkan admin | `voucher_redemptions.status` → `RELEASED`, kuota terpakai berkurang 1 |
| Voucher dengan `per_customer_limit` | pelanggan itu bisa pakai lagi |
| Order tanpa voucher | tidak error |
| Dibatalkan dua kali | kedua kali ditolak (status bukan PAID/FAILED/BLOCKED) |
| Order gagal/kedaluwarsa | perilaku lama tidak berubah |
| Kode stok digital | tetap kembali ke stok seperti sekarang |

## 11. Tetangga lintas butir
| Skenario | Harapan |
|---|---|
| Suite penuh | tidak ada regresi baru dibanding `bc387476` |
| Pint | lolos |
| Build frontend | lolos (3 halaman Vue berubah: OrderDetail, Fulfillment, Dashboard) |
| Guard rahasia pre-commit | tidak menandai konstanta tes |
| Tidak ada route/tombol refund baru | `grep -ri refund` tidak menambah jalur uang keluar |
