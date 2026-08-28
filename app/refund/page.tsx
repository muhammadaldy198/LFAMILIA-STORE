import { LegalPage } from "@/components/legal-page";

export default function RefundPage() {
  return <LegalPage eyebrow="Kebijakan transaksi" title="Kebijakan Refund" intro="Kebijakan ini menerangkan kondisi rancangan pengembalian dana untuk transaksi produk digital LFAMILIA STORE." sections={[
    { title: "Transaksi yang dapat ditinjau", items: ["Pembayaran berhasil tetapi pesanan gagal diproses oleh sistem atau pemasok.", "Pembayaran terduplikasi untuk invoice yang sama.", "Produk tidak tersedia dan tidak dapat dipenuhi dalam batas waktu penanganan."] },
    { title: "Transaksi yang tidak dapat direfund", items: ["Produk telah berstatus berhasil dikirim.", "Pengguna salah memilih produk, nominal, User ID, server, atau tujuan.", "Kode voucher telah ditampilkan, dikirim, atau digunakan.", "Masalah berasal dari akun pengguna yang diblokir atau tidak memenuhi ketentuan publisher."] },
    { title: "Cara mengajukan", paragraphs: ["Hubungi dukungan resmi dengan menyertakan nomor invoice, bukti pembayaran, dan penjelasan kendala. Jangan membagikan kata sandi atau kode OTP."] },
    { title: "Waktu penanganan", paragraphs: ["Pemeriksaan memerlukan verifikasi kepada penyedia pembayaran dan pemasok. Estimasi waktu final akan ditetapkan setelah prosedur operasional dan mitra aktif."] },
    { title: "Metode pengembalian", paragraphs: ["Refund yang disetujui akan dikembalikan melalui metode yang memungkinkan sesuai prosedur penyedia pembayaran. Biaya yang tidak dikembalikan oleh penyedia dapat tunduk pada kebijakannya."] },
  ]} />;
}
