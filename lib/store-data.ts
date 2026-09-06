// Categories are managed from the admin panel, so this intentionally remains
// open-ended instead of limiting the catalog to a fixed set of slugs.
export type ProductCategory = string;

export type ProductPackage = {
  id: string;
  label: string;
  price: number;
  note?: string;
  providerCode?: string;
  providerSku?: string;
};

export type FulfillmentType = "automatic" | "manual";

export type ProductNotice = {
  id?: number | null;
  title: string;
  body: string;
  isActive?: boolean;
  sortOrder?: number;
};

export type ProductInputField = {
  id: string;
  label: string;
  placeholder?: string;
  required?: boolean;
};

export type StoreProduct = {
  slug: string;
  name: string;
  publisher: string;
  category: ProductCategory;
  imageUrl?: string;
  bannerUrl?: string;
  initials: string;
  accent: string;
  popular?: boolean;
  instant?: boolean;
  fulfillmentType: FulfillmentType;
  targetTemplate: string;
  manualInstructions?: string;
  manualOpenTime?: string;
  manualCloseTime?: string;
  manualTimezone?: string;
  inputFields?: ProductInputField[];
  needsServer?: boolean;
  inputLabel: string;
  inputPlaceholder: string;
  notices?: ProductNotice[];
  ratingAverage?: number;
  ratingCount?: number;
  packages: ProductPackage[];
};

const productDefinitions: StoreProduct[] = [
  {
    slug: "mobile-legends", name: "Mobile Legends", publisher: "Moonton", category: "game", initials: "ML",
    imageUrl: "/products/mobile-legends-card.webp",
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
    imageUrl: "/products/free-fire-card.webp",
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
    imageUrl: "/products/pubg-mobile-card.webp",
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
    imageUrl: "/products/honor-of-kings-card.webp",
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
    imageUrl: "/products/genshin-impact-card.webp",
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
    imageUrl: "/products/valorant-card.webp",
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
    manualOpenTime: "09:00", manualCloseTime: "21:00", manualTimezone: "Asia/Jakarta",
    notices: [{ title: "JAM OPERASIONAL {{jam_buka}} – {{jam_tutup}} {{zona_waktu}}", body: "Estimasi proses 30 menit sampai 2 jam.\n\nProduk ini diproses manual. Admin akan menghubungi melalui WhatsApp setelah pembayaran berhasil." }],
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
    manualOpenTime: "09:00", manualCloseTime: "21:00", manualTimezone: "Asia/Jakarta",
    notices: [{ title: "JAM OPERASIONAL {{jam_buka}} – {{jam_tutup}} {{zona_waktu}}", body: "Estimasi proses mengikuti antrean admin.\n\nPastikan username dan nama item sudah benar. Admin akan menghubungi melalui WhatsApp setelah pembayaran berhasil." }],
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
    manualOpenTime: "09:00", manualCloseTime: "21:00", manualTimezone: "Asia/Jakarta",
    notices: [{ title: "JAM OPERASIONAL {{jam_buka}} – {{jam_tutup}} {{zona_waktu}}", body: "Estimasi proses 30 menit sampai 2 jam.\n\nProduk ini diproses via login. Jangan pernah mengirim OTP melalui form website; admin akan menghubungi melalui WhatsApp setelah pembayaran berhasil." }],
    inputLabel: "Username Roblox (tanpa password)", inputPlaceholder: "Masukkan username Roblox",
    packages: [
      { id: "roblox-login-100", label: "100 Robux Via Login", price: 18000, note: "Manual" },
      { id: "roblox-login-500", label: "500 Robux Via Login", price: 85000 },
      { id: "roblox-login-1000", label: "1.000 Robux Via Login", price: 165000 },
    ],
  },
  {
    slug: "call-of-duty-mobile", name: "Call of Duty Mobile", publisher: "Activision", category: "game", initials: "COD",
    imageUrl: "/products/call-of-duty-mobile-card.webp",
    accent: "from-[#f5cf55] via-[#6d5b25] to-[#17150d]", popular: true, instant: true,
    fulfillmentType: "automatic", targetTemplate: "{{destination}}",
    inputLabel: "Player ID", inputPlaceholder: "Masukkan Player ID",
    packages: [
      { id: "codm-31", label: "31 CP", price: 6000 }, { id: "codm-63", label: "63 CP", price: 11500 },
      { id: "codm-128", label: "128 CP", price: 22500, note: "Populer" }, { id: "codm-645", label: "645 CP", price: 108000 },
    ],
  },
  {
    slug: "wild-rift", name: "League of Legends: Wild Rift", publisher: "Riot Games", category: "game", initials: "WR",
    accent: "from-[#59d7e8] via-[#196b9c] to-[#112951]", instant: true,
    fulfillmentType: "automatic", targetTemplate: "{{destination}}",
    inputLabel: "Riot ID", inputPlaceholder: "Contoh: Player#TAG",
    packages: [
      { id: "wr-425", label: "425 Wild Cores", price: 49000 }, { id: "wr-1000", label: "1.000 Wild Cores", price: 109000, note: "Populer" },
      { id: "wr-2050", label: "2.050 Wild Cores", price: 219000 },
    ],
  },
  {
    slug: "arena-of-valor", name: "Arena of Valor", publisher: "Garena", category: "game", initials: "AOV",
    accent: "from-[#eecc72] via-[#7b4d2b] to-[#251711]", instant: true,
    fulfillmentType: "automatic", targetTemplate: "{{destination}}",
    inputLabel: "Player ID", inputPlaceholder: "Masukkan Player ID",
    packages: [
      { id: "aov-40", label: "40 Vouchers", price: 10000 }, { id: "aov-90", label: "90 Vouchers", price: 21000 },
      { id: "aov-230", label: "230 Vouchers", price: 51000, note: "Populer" },
    ],
  },
  {
    slug: "fc-mobile", name: "EA SPORTS FC Mobile", publisher: "Electronic Arts", category: "game", initials: "FC",
    accent: "from-[#54e884] via-[#16835c] to-[#0b2821]", instant: true,
    fulfillmentType: "automatic", targetTemplate: "{{destination}}",
    inputLabel: "User ID", inputPlaceholder: "Masukkan User ID",
    packages: [
      { id: "fcm-40", label: "40 FC Points", price: 9000 }, { id: "fcm-100", label: "100 FC Points", price: 21000 },
      { id: "fcm-520", label: "520 FC Points", price: 99000, note: "Populer" },
    ],
  },
  {
    slug: "efootball", name: "eFootball", publisher: "Konami", category: "game", initials: "EF",
    accent: "from-[#397cff] via-[#4531c8] to-[#171450]", instant: true,
    fulfillmentType: "automatic", targetTemplate: "{{destination}}",
    inputLabel: "User ID", inputPlaceholder: "Masukkan User ID eFootball",
    packages: [
      { id: "ef-130", label: "130 Coins", price: 19000 }, { id: "ef-300", label: "300 Coins", price: 42000, note: "Populer" },
      { id: "ef-550", label: "550 Coins", price: 75000 },
    ],
  },
  {
    slug: "point-blank", name: "Point Blank", publisher: "Zepetto", category: "game", initials: "PB",
    accent: "from-[#ef6d55] via-[#8a2d2a] to-[#2c1112]", instant: true,
    fulfillmentType: "automatic", targetTemplate: "{{destination}}",
    inputLabel: "User ID", inputPlaceholder: "Masukkan User ID Point Blank",
    packages: [
      { id: "pb-1200", label: "1.200 Cash", price: 10000 }, { id: "pb-2400", label: "2.400 Cash", price: 20000 },
      { id: "pb-6000", label: "6.000 Cash", price: 50000, note: "Populer" },
    ],
  },
  {
    slug: "garena-shells", name: "Garena Shells", publisher: "Garena", category: "voucher", initials: "GS",
    accent: "from-[#ee524b] via-[#a51f28] to-[#390d16]", popular: true, instant: true,
    fulfillmentType: "automatic", targetTemplate: "{{destination}}",
    inputLabel: "Nomor WhatsApp / email", inputPlaceholder: "Untuk menerima kode voucher",
    packages: [
      { id: "gs-33", label: "33 Shells", price: 11000 }, { id: "gs-66", label: "66 Shells", price: 21000 },
      { id: "gs-165", label: "165 Shells", price: 51000, note: "Populer" },
    ],
  },
  {
    slug: "razer-gold", name: "Razer Gold", publisher: "Razer", category: "voucher", initials: "RZ",
    accent: "from-[#7dff59] via-[#19813a] to-[#0d2c20]", instant: true,
    fulfillmentType: "automatic", targetTemplate: "{{destination}}",
    inputLabel: "Nomor WhatsApp / email", inputPlaceholder: "Untuk menerima PIN voucher",
    packages: [
      { id: "rz-20", label: "Razer Gold Rp20.000", price: 22000 }, { id: "rz-50", label: "Razer Gold Rp50.000", price: 53500, note: "Populer" },
      { id: "rz-100", label: "Razer Gold Rp100.000", price: 106000 },
    ],
  },
  {
    slug: "unipin-voucher", name: "UniPin Voucher", publisher: "UniPin", category: "voucher", initials: "UP",
    accent: "from-[#ff785a] via-[#bf3e4e] to-[#43172b]", instant: true,
    fulfillmentType: "automatic", targetTemplate: "{{destination}}",
    inputLabel: "Nomor WhatsApp / email", inputPlaceholder: "Untuk menerima kode voucher",
    packages: [
      { id: "up-20", label: "UniPin Rp20.000", price: 22000 }, { id: "up-50", label: "UniPin Rp50.000", price: 53500 },
      { id: "up-100", label: "UniPin Rp100.000", price: 106000, note: "Populer" },
    ],
  },
  {
    slug: "xbox-gift-card", name: "Xbox Gift Card", publisher: "Microsoft", category: "voucher", initials: "XB",
    accent: "from-[#69cc67] via-[#248239] to-[#14341d]", instant: true,
    fulfillmentType: "automatic", targetTemplate: "{{destination}}",
    inputLabel: "Nomor WhatsApp / email", inputPlaceholder: "Untuk menerima kode voucher",
    packages: [
      { id: "xbox-100", label: "Xbox Rp100.000", price: 109000 }, { id: "xbox-200", label: "Xbox Rp200.000", price: 216000, note: "Populer" },
    ],
  },
  {
    slug: "nintendo-eshop", name: "Nintendo eShop", publisher: "Nintendo", category: "voucher", initials: "NS",
    accent: "from-[#ff6969] via-[#c83045] to-[#481522]", instant: true,
    fulfillmentType: "automatic", targetTemplate: "{{destination}}",
    inputLabel: "Nomor WhatsApp / email", inputPlaceholder: "Untuk menerima kode voucher",
    packages: [
      { id: "nintendo-10", label: "Nintendo eShop $10", price: 175000 }, { id: "nintendo-20", label: "Nintendo eShop $20", price: 338000, note: "Populer" },
    ],
  },
  {
    slug: "redfinger", name: "REDFINGER Cloud Phone", publisher: "REDFINGER", category: "voucher", initials: "RF",
    accent: "from-[#ff576c] via-[#962d68] to-[#321947]", popular: true, instant: true,
    fulfillmentType: "automatic", targetTemplate: "{{destination}}",
    inputLabel: "Email tujuan", inputPlaceholder: "Email untuk menerima lisensi",
    packages: [
      { id: "rf-7", label: "Paket 7 Hari", price: 35000, providerCode: "voucher-stock", providerSku: "redfinger-7-hari" },
      { id: "rf-30", label: "Paket 30 Hari", price: 110000, note: "Populer", providerCode: "voucher-stock", providerSku: "redfinger-30-hari" },
      { id: "rf-90", label: "Paket 90 Hari", price: 295000, providerCode: "voucher-stock", providerSku: "redfinger-90-hari" },
    ],
  },
];

export const products: StoreProduct[] = productDefinitions.map((product) => ({
  ...product,
  inputFields: product.inputFields ?? [
    {
      id: "account-id",
      label: product.inputLabel,
      placeholder: product.inputPlaceholder,
      required: true,
    },
    ...(product.needsServer
      ? [{
          id: "server-zone",
          label: "Server / Zone ID",
          placeholder: "Contoh: 1234",
          required: true,
        }]
      : []),
  ],
  imageUrl: product.imageUrl ?? `/products/${product.slug}-card.webp`,
  bannerUrl: product.bannerUrl ?? `/products/${product.slug}-banner.webp`,
}));

export type StorefrontSettings = {
  storeName: string;
  storeShortName: string;
  tagline: string;
  logoUrl?: string;
  announcement?: string;
  bannerEnabled: boolean;
  bannerEyebrow: string;
  bannerTitle: string;
  bannerHighlight: string;
  bannerDescription: string;
  bannerImageUrl?: string;
  bannerCtaLabel: string;
  bannerCtaHref: string;
  supportWhatsapp?: string;
  supportEmail?: string;
  instagramUrl?: string;
  discordUrl?: string;
  supportHours: string;
  supportWidgetEnabled: boolean;
};

export const defaultStorefrontSettings: StorefrontSettings = {
  storeName: "LFAMILIA STORE",
  storeShortName: "LF",
  tagline: "Top up favoritmu, sat set tanpa ribet.",
  logoUrl: "/brand/lfamilia-pixel-logo.webp",
  announcement: "Pemesanan tersedia 24 jam",
  bannerEnabled: true,
  bannerEyebrow: "Top up & voucher digital",
  bannerTitle: "Top up favoritmu,",
  bannerHighlight: "sat set tanpa ribet.",
  bannerDescription: "Game, voucher, promo, dan kalkulator dalam satu website LFAMILIA yang nyaman digunakan kapan saja.",
  bannerImageUrl: "/brand/lfamilia-pixel-hero.webp",
  bannerCtaLabel: "Top up sekarang",
  bannerCtaHref: "#produk",
  supportHours: "Setiap hari, 09.00–23.00 WIB",
  supportWidgetEnabled: true,
};

export const faqs = [
  { question: "Bagaimana cara melakukan top up?", answer: "Pilih produk, isi data tujuan dengan benar, pilih nominal, lalu selesaikan pembayaran. Pesanan otomatis mulai diproses setelah pembayaran terverifikasi." },
  { question: "Di mana saya dapat melihat status pesanan?", answer: "Buka menu Cek Transaksi lalu masukkan nomor invoice yang diterima setelah checkout. Simpan nomor invoice sampai produk berhasil diterima." },
  { question: "Mengapa pembayaran berhasil tetapi status masih menunggu?", answer: "Konfirmasi dari kanal pembayaran terkadang memerlukan waktu. Jangan melakukan pembayaran kedua untuk invoice yang sama. Jika status belum berubah setelah beberapa saat, kirim nomor invoice dan bukti pembayaran melalui halaman Hubungi Kami." },
  { question: "Apa arti status sedang diproses?", answer: "Pembayaran telah diterima dan pesanan sedang diteruskan ke pemasok atau antrean admin. Gangguan publisher, pemasok, atau jaringan dapat membuat proses lebih lama dari biasanya." },
  { question: "Bagaimana jika status pesanan gagal atau refund?", answer: "Tim akan memeriksa apakah pesanan dapat diproses ulang atau perlu dikembalikan. Ketentuan lengkap, bukti yang dibutuhkan, dan estimasi penanganan tersedia pada halaman Kebijakan Pengembalian Dana." },
  { question: "Apa perbedaan produk otomatis dan manual?", answer: "Produk otomatis diteruskan ke penyedia setelah pembayaran terverifikasi. Produk manual masuk antrean admin dan diproses mengikuti petunjuk serta jam layanan pada halaman produk." },
  { question: "Bagaimana kode voucher atau lisensi dikirim?", answer: "Kode dikirim melalui kanal privat yang tercantum pada pesanan setelah pembayaran lunas. Kode tidak ditampilkan pada halaman Cek Transaksi publik. Jaga kerahasiaan kode setelah diterima." },
  { question: "Apakah harga sudah termasuk biaya pembayaran?", answer: "Harga produk ditampilkan terpisah dari biaya kanal pembayaran. Total akhir selalu ditampilkan pada ringkasan sebelum kamu membuat atau membayar invoice." },
  { question: "Apakah pesanan dapat dibatalkan?", answer: "Pesanan yang belum dibayar dapat kedaluwarsa otomatis. Pesanan yang sudah diproses atau berhasil dikirim umumnya tidak dapat dibatalkan. Lihat Kebijakan Pengembalian Dana untuk kondisi yang dapat ditinjau." },
  { question: "Bagaimana jika saya salah memasukkan User ID atau server?", answer: "Segera hubungi bantuan jika pesanan belum diproses, tetapi penghentian tidak dapat dijamin. Produk yang telah berhasil dikirim sesuai data pada invoice umumnya tidak dapat dipindahkan atau direfund." },
];

export function formatRupiah(value: number) {
  return new Intl.NumberFormat("id-ID", { style: "currency", currency: "IDR", maximumFractionDigits: 0 }).format(value);
}
