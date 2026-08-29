export type ProductCategory = "game" | "voucher";

export type ProductPackage = {
  id: string;
  label: string;
  price: number;
  note?: string;
  providerCode?: string;
  providerSku?: string;
};

export type FulfillmentType = "automatic" | "manual";

export type StoreProduct = {
  slug: string;
  name: string;
  publisher: string;
  category: ProductCategory;
  initials: string;
  accent: string;
  popular?: boolean;
  instant?: boolean;
  fulfillmentType: FulfillmentType;
  targetTemplate: string;
  manualInstructions?: string;
  needsServer?: boolean;
  inputLabel: string;
  inputPlaceholder: string;
  packages: ProductPackage[];
};

export const products: StoreProduct[] = [
  {
    slug: "mobile-legends", name: "Mobile Legends", publisher: "Moonton", category: "game", initials: "ML",
    accent: "from-[#5577ff] via-[#314fc0] to-[#16276c]", popular: true, instant: true, needsServer: true,
    fulfillmentType: "automatic", targetTemplate: "{{destination}}{{server}}",
    inputLabel: "User ID", inputPlaceholder: "Contoh: 123456789",
    packages: [
      { id: "ml-5", label: "5 Diamonds", price: 2500 }, { id: "ml-12", label: "12 Diamonds", price: 4500 },
      { id: "ml-28", label: "28 Diamonds", price: 9000 }, { id: "ml-59", label: "59 Diamonds", price: 17500, note: "Populer" },
      { id: "ml-170", label: "170 Diamonds", price: 48000 }, { id: "ml-weekly", label: "Weekly Diamond Pass", price: 28500 },
    ],
  },
  {
    slug: "free-fire", name: "Free Fire", publisher: "Garena", category: "game", initials: "FF",
    accent: "from-[#ffad32] via-[#ea6825] to-[#7c2714]", popular: true, instant: true,
    fulfillmentType: "automatic", targetTemplate: "{{destination}}",
    inputLabel: "Player ID", inputPlaceholder: "Contoh: 1234567890",
    packages: [
      { id: "ff-5", label: "5 Diamonds", price: 1500 }, { id: "ff-20", label: "20 Diamonds", price: 4000 },
      { id: "ff-70", label: "70 Diamonds", price: 11000, note: "Populer" }, { id: "ff-140", label: "140 Diamonds", price: 20500 },
      { id: "ff-355", label: "355 Diamonds", price: 50500 }, { id: "ff-member", label: "Membership Mingguan", price: 28500 },
    ],
  },
  {
    slug: "pubg-mobile", name: "PUBG Mobile", publisher: "Level Infinite", category: "game", initials: "PM",
    accent: "from-[#f3ca52] via-[#b48624] to-[#4f3510]", popular: true, instant: true,
    fulfillmentType: "automatic", targetTemplate: "{{destination}}",
    inputLabel: "Player ID", inputPlaceholder: "Contoh: 51234567890",
    packages: [
      { id: "pubg-60", label: "60 UC", price: 15500 }, { id: "pubg-325", label: "325 UC", price: 73500, note: "Populer" },
      { id: "pubg-660", label: "660 UC", price: 145000 }, { id: "pubg-1800", label: "1.800 UC", price: 358000 },
    ],
  },
  {
    slug: "honor-of-kings", name: "Honor of Kings", publisher: "Level Infinite", category: "game", initials: "HK",
    accent: "from-[#f6d878] via-[#7c4fc9] to-[#2b174c]", instant: true,
    fulfillmentType: "automatic", targetTemplate: "{{destination}}",
    inputLabel: "User ID", inputPlaceholder: "Masukkan User ID",
    packages: [
      { id: "hok-16", label: "16 Tokens", price: 4500 }, { id: "hok-80", label: "80 Tokens", price: 18500 },
      { id: "hok-240", label: "240 Tokens", price: 52000, note: "Populer" }, { id: "hok-400", label: "400 Tokens", price: 85500 },
    ],
  },
  {
    slug: "genshin-impact", name: "Genshin Impact", publisher: "HoYoverse", category: "game", initials: "GI",
    accent: "from-[#87d7e7] via-[#597db9] to-[#242b5b]",
    fulfillmentType: "automatic", targetTemplate: "{{destination}}",
    inputLabel: "UID", inputPlaceholder: "Contoh: 800123456",
    packages: [
      { id: "gi-welkin", label: "Blessing of the Welkin Moon", price: 59000, note: "Populer" },
      { id: "gi-60", label: "60 Genesis Crystals", price: 15000 }, { id: "gi-330", label: "330 Genesis Crystals", price: 75000 },
      { id: "gi-1090", label: "1.090 Genesis Crystals", price: 239000 },
    ],
  },
  {
    slug: "valorant", name: "Valorant", publisher: "Riot Games", category: "game", initials: "VL",
    accent: "from-[#ff5f65] via-[#c42f50] to-[#5b1530]", inputLabel: "Riot ID", inputPlaceholder: "Contoh: Player#TAG",
    fulfillmentType: "automatic", targetTemplate: "{{destination}}",
    packages: [
      { id: "val-125", label: "125 Points", price: 16000 }, { id: "val-420", label: "420 Points", price: 50000, note: "Populer" },
      { id: "val-700", label: "700 Points", price: 80000 }, { id: "val-1375", label: "1.375 Points", price: 150000 },
    ],
  },
  {
    slug: "steam-wallet", name: "Steam Wallet", publisher: "Valve", category: "voucher", initials: "SW",
    accent: "from-[#4da4d9] via-[#1b5a8c] to-[#10283b]", popular: true,
    fulfillmentType: "automatic", targetTemplate: "{{destination}}",
    inputLabel: "Nomor WhatsApp / email", inputPlaceholder: "Untuk menerima kode voucher",
    packages: [
      { id: "steam-12", label: "Steam Wallet Rp12.000", price: 14000 }, { id: "steam-45", label: "Steam Wallet Rp45.000", price: 49000, note: "Populer" },
      { id: "steam-90", label: "Steam Wallet Rp90.000", price: 96000 }, { id: "steam-120", label: "Steam Wallet Rp120.000", price: 127000 },
    ],
  },
  {
    slug: "google-play", name: "Google Play", publisher: "Google", category: "voucher", initials: "GP",
    accent: "from-[#58d68d] via-[#2b8f9a] to-[#174862]", inputLabel: "Nomor WhatsApp / email", inputPlaceholder: "Untuk menerima kode voucher",
    fulfillmentType: "automatic", targetTemplate: "{{destination}}",
    packages: [
      { id: "gp-20", label: "Google Play Rp20.000", price: 22000 }, { id: "gp-50", label: "Google Play Rp50.000", price: 54000, note: "Populer" },
      { id: "gp-100", label: "Google Play Rp100.000", price: 107000 }, { id: "gp-150", label: "Google Play Rp150.000", price: 160000 },
    ],
  },
  {
    slug: "playstation-store", name: "PlayStation Store", publisher: "Sony", category: "voucher", initials: "PS",
    accent: "from-[#4c8fff] via-[#144fa8] to-[#12235d]", inputLabel: "Nomor WhatsApp / email", inputPlaceholder: "Untuk menerima kode voucher",
    fulfillmentType: "automatic", targetTemplate: "{{destination}}",
    packages: [
      { id: "ps-100", label: "PSN Rp100.000", price: 108000 }, { id: "ps-200", label: "PSN Rp200.000", price: 214000, note: "Populer" },
      { id: "ps-400", label: "PSN Rp400.000", price: 425000 },
    ],
  },
  {
    slug: "roblox-gamepass", name: "Roblox Gamepass", publisher: "Roblox Corporation", category: "game", initials: "RG",
    accent: "from-[#6d7b91] via-[#303845] to-[#11151c]", popular: true, instant: false,
    fulfillmentType: "manual", targetTemplate: "{{destination}}",
    manualInstructions: "Masukkan link Gamepass yang benar. Pesanan diperiksa admin dan diproses manual setelah pembayaran.",
    inputLabel: "Link Gamepass", inputPlaceholder: "https://www.roblox.com/game-pass/...",
    packages: [
      { id: "roblox-gp-100", label: "100 Robux via Gamepass", price: 20000, note: "Manual" },
      { id: "roblox-gp-500", label: "500 Robux via Gamepass", price: 95000 },
      { id: "roblox-gp-1000", label: "1.000 Robux via Gamepass", price: 185000 },
    ],
  },
  {
    slug: "roblox-gift-in-game", name: "Roblox Gift in Game", publisher: "Roblox Corporation", category: "game", initials: "RI",
    accent: "from-[#ff5a5f] via-[#a6213f] to-[#35101f]", instant: false,
    fulfillmentType: "manual", targetTemplate: "{{destination}}",
    manualInstructions: "Masukkan username dan nama item yang ingin diterima. Admin akan menghubungi melalui WhatsApp untuk jadwal pengiriman.",
    inputLabel: "Username Roblox", inputPlaceholder: "Masukkan username Roblox",
    packages: [
      { id: "roblox-gift-small", label: "Gift in Game — Paket S", price: 25000, note: "Manual" },
      { id: "roblox-gift-medium", label: "Gift in Game — Paket M", price: 50000 },
      { id: "roblox-gift-large", label: "Gift in Game — Paket L", price: 100000 },
    ],
  },
  {
    slug: "roblox-via-login", name: "Roblox Via Login", publisher: "Roblox Corporation", category: "game", initials: "RL",
    accent: "from-[#8d69ff] via-[#5531a5] to-[#24154d]", instant: false,
    fulfillmentType: "manual", targetTemplate: "{{destination}}",
    manualInstructions: "Masukkan username saja—jangan pernah masukkan password atau kode OTP di website. Detail aman akan dikonfirmasi admin melalui WhatsApp.",
    inputLabel: "Username Roblox (tanpa password)", inputPlaceholder: "Masukkan username Roblox",
    packages: [
      { id: "roblox-login-100", label: "100 Robux Via Login", price: 18000, note: "Manual" },
      { id: "roblox-login-500", label: "500 Robux Via Login", price: 85000 },
      { id: "roblox-login-1000", label: "1.000 Robux Via Login", price: 165000 },
    ],
  },
];

export const demoOrder = {
  id: "DEMO-20260828-001", product: "Mobile Legends", item: "59 Diamonds", destination: "123456789 (1234)",
  amount: 17624, status: "Berhasil", createdAt: "28 Agustus 2026, 14.32 WIB",
};

export const faqs = [
  { question: "Bagaimana cara melakukan top up?", answer: "Pilih produk, isi data akun game, pilih nominal, lalu lakukan pembayaran. Saat integrasi aktif, pesanan diproses otomatis setelah pembayaran terverifikasi." },
  { question: "Berapa lama pesanan diproses?", answer: "Target proses otomatis adalah beberapa menit setelah pembayaran berhasil. Gangguan dari publisher atau pemasok dapat membuat proses lebih lama." },
  { question: "Metode pembayaran apa yang tersedia?", answer: "LFAMILIA STORE menggunakan iPaymu untuk Virtual Account bank, DANA, ShopeePay, dan QRIS. Biaya layanan pembayaran dibebankan kepada pembeli." },
  { question: "Apa perbedaan produk otomatis dan manual?", answer: "Produk otomatis diteruskan ke provider resmi seperti DigiFlazz atau VIPayment setelah pembayaran terverifikasi. Produk manual masuk antrean admin dan diproses sesuai instruksi serta jadwal layanan." },
  { question: "Bagaimana kode voucher atau lisensi dikirim?", answer: "Untuk produk berlabel Stok Kode Internal, sistem mereservasi satu kode setelah pembayaran lunas lalu mengirimkannya otomatis ke email dan/atau WhatsApp pembeli. Kode tidak tampil di halaman cek transaksi publik." },
  { question: "Apakah harga di website ini sudah final?", answer: "Belum. Semua harga saat ini adalah data demo untuk menguji tampilan. Harga final akan mengikuti harga pemasok dan margin toko setelah API diaktifkan." },
  { question: "Bagaimana jika saya salah memasukkan User ID?", answer: "Periksa kembali data tujuan sebelum membayar. Produk digital yang telah sukses dikirim ke tujuan yang dimasukkan umumnya tidak dapat dibatalkan." },
  { question: "Di mana saya bisa melihat status pesanan?", answer: "Buka menu Cek Transaksi dan masukkan nomor invoice dari checkout. Untuk mencoba tampilannya tanpa transaksi, gunakan DEMO-20260828-001." },
];

export function formatRupiah(value: number) {
  return new Intl.NumberFormat("id-ID", { style: "currency", currency: "IDR", maximumFractionDigits: 0 }).format(value);
}
