import { LegalPage } from "@/components/legal-page";

export default function PrivacyPage() {
  return <LegalPage eyebrow="Dokumen hukum" title="Kebijakan Privasi" intro="Kebijakan ini menjelaskan rancangan cara LFAMILIA STORE mengumpulkan, menggunakan, menyimpan, dan melindungi informasi pengguna." sections={[
    { title: "Informasi yang dikumpulkan", paragraphs: ["Kami dapat mengumpulkan data yang Anda berikan saat membuat akun atau bertransaksi."], items: ["Nama, alamat email, dan nomor WhatsApp.", "User ID, server, atau data tujuan produk digital.", "Nomor invoice dan rincian transaksi.", "Data teknis dasar seperti alamat IP dan perangkat untuk keamanan."] },
    { title: "Penggunaan informasi", items: ["Memproses dan mengirim produk yang dipesan.", "Mengirim status transaksi dan bantuan pelanggan.", "Mencegah penipuan dan penyalahgunaan layanan.", "Meningkatkan performa dan pengalaman website."] },
    { title: "Penyedia layanan", paragraphs: ["Data transaksi tertentu dapat diteruskan secara terbatas kepada penyedia pembayaran, pemasok produk digital, atau penyedia infrastruktur yang diperlukan untuk menyelesaikan pesanan. Masing-masing penyedia memiliki kebijakan privasinya sendiri."] },
    { title: "Penyimpanan dan keamanan", paragraphs: ["Kami merencanakan langkah teknis dan organisasi yang wajar untuk melindungi data. Tidak ada sistem internet yang sepenuhnya bebas risiko, sehingga akses akan dibatasi sesuai kebutuhan operasional."] },
    { title: "Hak pengguna", paragraphs: ["Anda dapat meminta akses, pembaruan, atau penghapusan data akun sejauh tidak bertentangan dengan kewajiban penyimpanan transaksi dan ketentuan hukum yang berlaku."] },
    { title: "Kontak", paragraphs: ["Pertanyaan privasi dapat dikirim melalui kanal dukungan resmi yang akan dicantumkan di website sebelum layanan aktif."] },
  ]} />;
}

