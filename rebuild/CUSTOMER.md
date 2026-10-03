# Customer account & guest

## Registered customer

Area account yang diimplementasikan mencakup profile, password/account actions, wallet dan ledger, membership tier, riwayat/detail order, support ticket, serta saved game account sesuai route/controller aktual.

Wallet customer dibuat/diambil server-side dan tidak boleh negatif. Perubahan saldo operasional menggunakan ledger dan transaction/locking; customer tidak dapat mengubah saldo dengan mengirim nominal arbitrer.

Membership memakai tabel/config tier dan tetap terpisah dari Admin RBAC:

BASIC → SILVER → GOLD → DIAMOND → PLATINUM → MAFIA.

## Guest

Guest checkout didukung di website. Guest tidak mempunyai wallet. Akses status order guest memakai identifier/access mechanism server-side dan data guest tidak boleh diekspos dari lookup publik.

Support guest tersedia. Review produk/order menggunakan eligibility yang berasal dari order, bukan isi review yang dibuat sistem.

## Lifecycle

Self-delete/cleanup hanya boleh berjalan pada kondisi aman yang diperiksa backend. Business record yang diperlukan untuk transaksi/audit tidak boleh dihapus hanya karena customer meminta penghapusan profile.

## Security

Sensitive saved-game data tidak boleh dibocorkan pada list Admin/customer yang tidak memerlukan nilainya. Endpoint account dan support menggunakan auth/rate limit sesuai implementasi.

Untuk auth detail lihat [AUTH.md](AUTH.md); untuk wallet/payment lihat [PAYMENT.md](PAYMENT.md).
