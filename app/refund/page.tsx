import { LegalPage } from "@/components/legal-page";

export default function RefundPage() {
  return <LegalPage
    eyebrow="Kebijakan transaksi"
    title="Kebijakan Pengembalian Dana"
    intro="Karena top up, voucher, dan lisensi merupakan produk digital yang dapat dikirim atau digunakan segera, refund hanya tersedia untuk kondisi tertentu. Kebijakan ini menjelaskan kelayakan, cara pengajuan, dan waktu penanganannya."
    highlights={[
      "Produk yang sudah berhasil dikirim atau kode yang sudah digunakan tidak dapat direfund.",
      "Kesalahan User ID, server, produk, atau nominal menjadi tanggung jawab pembeli.",
      "Ajukan kendala maksimal 7 hari kalender sejak transaksi dengan nomor invoice.",
    ]}
    sections={[
      { title: "Prinsip umum produk digital", paragraphs: ["Pesanan yang telah berstatus berhasil dan terkirim ke data tujuan dianggap selesai. Produk digital tidak dapat ditarik kembali setelah diserahkan, sehingga perubahan pikiran atau kesalahan pilihan pengguna bukan dasar refund. Setiap permintaan tetap diperiksa berdasarkan catatan sistem, pemasok, dan pembayaran."] },
      { title: "Kondisi yang dapat diajukan", items: ["Pembayaran terverifikasi, tetapi pesanan dinyatakan gagal oleh sistem atau pemasok dan tidak dapat diproses ulang.", "Terjadi pembayaran ganda untuk invoice atau pesanan yang sama.", "Produk ternyata tidak tersedia dan tidak dapat dipenuhi dalam waktu penanganan yang wajar.", "Nominal yang dikirim lebih kecil daripada produk yang dibayar dan selisih tidak dapat dipenuhi.", "Transaksi pembayaran dilaporkan tidak sah sebelum produk dikirim, setelah verifikasi kepemilikan dan pemeriksaan keamanan."] },
      { title: "Kondisi yang tidak dapat direfund", items: ["Produk telah berstatus berhasil atau terkonfirmasi terkirim ke data tujuan.", "Pengguna salah memilih game, produk, nominal, User ID, server, region, email, atau nomor tujuan.", "Kode voucher atau lisensi sudah ditampilkan, dikirim, dibuka, atau digunakan.", "Akun game diblokir, dibatasi, tidak memenuhi syarat wilayah, atau bermasalah karena kebijakan publisher.", "Bonus, event, promosi, atau hadiah dari publisher tidak diterima meskipun produk utama telah terkirim.", "Permintaan diajukan hanya karena perubahan pikiran, menemukan harga lain, atau keterlambatan singkat yang masih dalam proses wajar."] },
      { title: "Kesalahan data tujuan", paragraphs: ["Sebelum membayar, pengguna wajib memeriksa kembali seluruh data tujuan. Jika pemasok telah mengirim produk sesuai data pada invoice, LFAMILIA tidak dapat memindahkan produk atau mengambilnya kembali. Apabila pesanan belum diproses, segera hubungi bantuan; penghentian pesanan tidak dapat dijamin."] },
      { title: "Cara mengajukan refund", paragraphs: ["Ajukan melalui halaman Hubungi Kami paling lambat 7 hari kalender sejak transaksi, kecuali gangguan masih berstatus aktif dan telah dilaporkan sebelumnya."], items: ["Cantumkan nomor invoice dan nama pembeli.", "Sertakan bukti pembayaran yang jelas serta screenshot status atau kendala.", "Jelaskan kronologi secara singkat, termasuk waktu kejadian dan data tujuan yang digunakan.", "Gunakan kanal resmi dan jangan pernah mengirim password, PIN, atau kode OTP."] },
      { title: "Pemeriksaan permintaan", paragraphs: ["Tim akan memeriksa pembayaran, log pesanan, status pemasok, dan bukti yang diberikan. Pemeriksaan umumnya memerlukan 1–7 hari kerja, tetapi dapat lebih lama jika menunggu konfirmasi bank, gateway pembayaran, pemasok, atau publisher. Permintaan tambahan informasi harus dijawab agar pemeriksaan dapat dilanjutkan."] },
      { title: "Keputusan dan metode pengembalian", paragraphs: ["Jika disetujui, refund diproses ke metode pembayaran asal jika didukung. Metode lain atau saldo akun hanya digunakan setelah disepakati dengan pengguna. Nilai refund mengikuti jumlah yang dinyatakan layak; biaya kanal yang tidak dikembalikan oleh penyedia pembayaran dapat dikecualikan dan akan dijelaskan pada hasil pemeriksaan."] },
      { title: "Waktu dana diterima", paragraphs: ["Setelah refund diterbitkan oleh LFAMILIA, waktu dana masuk bergantung pada bank, dompet digital, atau gateway pembayaran. Proses pihak tersebut dapat memerlukan hingga 14 hari kerja. Jika melewati estimasi, hubungi bantuan dengan nomor invoice dan bukti keputusan refund agar dapat ditelusuri."] },
      { title: "Pencegahan penyalahgunaan", paragraphs: ["Kami dapat menolak permintaan dengan bukti palsu, informasi yang tidak konsisten, pola penyalahgunaan promo, atau chargeback yang tidak sah. Akun dan transaksi terkait dapat dibatasi selama pemeriksaan keamanan, tanpa mengurangi hak pengguna yang diberikan oleh hukum."] },
      { title: "Kontak dan perubahan kebijakan", paragraphs: ["Seluruh pengajuan dan pertanyaan refund harus melalui kanal resmi pada halaman Hubungi Kami. Kebijakan ini dapat diperbarui untuk menyesuaikan produk, mitra pembayaran, pemasok, dan ketentuan yang berlaku; versi terbaru akan menampilkan tanggal pembaruan."] },
    ]}
  />;
}
