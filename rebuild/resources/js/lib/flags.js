// Bendera negara disimpan sebagai gambar sendiri di /public/flags.
// Emoji bendera tidak dipakai karena Windows tidak merendernya (kotak kosong).
export const flagUrl = (iso) => `/flags/${String(iso || '').toLowerCase()}.png`;
