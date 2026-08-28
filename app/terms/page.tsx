import { LegalPage } from "@/components/legal-page";

export default function TermsPage() {
  return <LegalPage eyebrow="Dokumen hukum" title="Syarat & Ketentuan" intro="Dengan menggunakan LFAMILIA STORE, pengguna dianggap telah membaca dan menyetujui ketentuan layanan produk digital berikut." sections={[
    { title: "Ruang lingkup layanan", paragraphs: ["LFAMILIA STORE menyediakan sarana pembelian produk game dan voucher digital. Ketersediaan, harga, serta waktu proses dapat berubah mengikuti pemasok dan publisher."] },
    { title: "Kewajiban pengguna", items: ["Memberikan data tujuan yang lengkap dan benar.", "Memeriksa produk dan nominal sebelum melakukan pembayaran.", "Menggunakan metode pembayaran yang sah dan memiliki izin.", "Tidak melakukan aktivitas yang merugikan layanan atau pihak lain."] },
    { title: "Harga dan pembayaran", paragraphs: ["Total pembayaran akan ditampilkan sebelum pelanggan menyelesaikan transaksi. Biaya layanan atau biaya pembayaran, jika ada, harus ditampilkan secara transparan dan mengikuti kebijakan penyedia pembayaran."] },
    { title: "Pemrosesan pesanan", paragraphs: ["Pesanan mulai diproses setelah pembayaran terverifikasi. Status berhasil dari pemasok dianggap sebagai bukti produk telah dikirim ke data tujuan yang diberikan pengguna."] },
    { title: "Kesalahan data", paragraphs: ["Pengguna bertanggung jawab atas User ID, server, email, atau nomor tujuan yang dimasukkan. Pesanan yang berhasil dikirim ke data yang salah umumnya tidak dapat dibatalkan."] },
    { title: "Gangguan layanan", paragraphs: ["Pemeliharaan, gangguan publisher, pemasok, pembayaran, jaringan, atau keadaan di luar kendali wajar dapat menunda pesanan. Kami akan memberikan status dan bantuan yang tersedia."] },
    { title: "Perubahan ketentuan", paragraphs: ["Ketentuan dapat diperbarui untuk menyesuaikan fitur, mitra, dan peraturan. Tanggal pembaruan akan dicantumkan pada dokumen ini."] },
  ]} />;
}

