// Recommendations follow customer layout slots, independent of stored media dimensions.
export function mediaRecommendation(type, collection = 'image', assetKey = '') {
    const home = collection === 'mobile' || assetKey === 'banner_mobile'
        ? 'Banner mobile: 1320 × 600 px (11:5). Area tampil mengikuti rasio 11:5; simpan teks penting di tengah.'
        : 'Banner desktop: 2560 × 800 px (16:5), untuk area hingga 1280 px. Tinggi desktop mengikuti proporsi gambar; gunakan rasio ini agar konsisten.';
    if (type === 'banner' || assetKey.startsWith('banner_')) return home;
    if (type === 'asset') {
        return ({
            logo: 'Logo: 256 × 256 px (1:1), PNG/WebP transparan. Area header 34–42 px dan footer 50 px.',
            favicon: 'Favicon: 512 × 512 px (1:1). Pastikan simbol terbaca pada tab browser 16–32 px.',
            footer_banner_mobile: 'Footer mobile: 780 × 140 px. Patokan area 390 × 70 px; gambar dipotong dari tengah saat lebar layar berubah.',
            footer_banner_desktop: 'Footer desktop: 3840 × 232 px. Patokan area 1920 × 116 px; tinggi area 72–116 px mengikuti layar. Simpan tulisan/logo dalam area tengah.',
        })[assetKey] || 'Gunakan gambar tajam; rekomendasi mengikuti tempat gambar ditampilkan di customer frontend.';
    }
    if (type === 'product') return collection === 'banner'
        ? 'Banner produk: 2560 × 640 px untuk desktop. Mobile menampilkan area 2:1 (contoh 390 × 195 px); letakkan subjek utama dalam area tengah agar aman pada kedua tampilan.'
        : 'Card produk: 600 × 900 px (2:3), sesuai card customer hingga sekitar 200 × 300 px. Cover checkout memakai crop tengah, termasuk kotak di mobile.';
    if (type === 'category') return 'Ikon kategori: 128 × 128 px (1:1), PNG/WebP transparan. Ikon customer tampil sekitar 17–24 px.';
    if (type === 'package') return 'Ikon nominal: 256 × 256 px (1:1), PNG/WebP transparan. Area nominal dan ringkasan memakai gambar kotak sekitar 36–45 px.';
    if (type === 'news') return 'Berita: 1600 × 900 px (16:9). Card mobile sekitar 364 × 205 px; desktop dan sampul artikel dapat memotong gambar. Simpan subjek penting di tengah.';
    if (type === 'popup') return 'Pop-up: 1200 × 800 px (3:2). Panel hingga 570 px; tinggi gambar dibatasi 42% layar desktop dan 34% layar mobile. Hindari teks penting di tepi.';
    return 'Gunakan JPEG/PNG/WebP tajam dengan ukuran berkas efisien.';
}
