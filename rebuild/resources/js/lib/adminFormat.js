// Format tampilan admin. Tidak mengubah data apa pun.
export const num = (value) => new Intl.NumberFormat('id-ID').format(Number(value ?? 0));

export const rupiah = (value) => 'Rp ' + num(value);

const WIB = { timeZone: 'Asia/Jakarta' };

export const wibDateTime = (iso) => {
    if (!iso) return '—';
    const date = new Date(iso);
    if (Number.isNaN(date.getTime())) return '—';
    return new Intl.DateTimeFormat('id-ID', {
        ...WIB, day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit',
    }).format(date).replace('.', ':') + ' WIB';
};

export const wibTime = (iso) => {
    if (!iso) return '—';
    const date = new Date(iso);
    if (Number.isNaN(date.getTime())) return '—';
    return new Intl.DateTimeFormat('id-ID', { ...WIB, hour: '2-digit', minute: '2-digit' }).format(date).replace('.', ':') + ' WIB';
};

export const wibDate = (iso) => {
    if (!iso) return '—';
    const date = new Date(iso);
    if (Number.isNaN(date.getTime())) return '—';
    return new Intl.DateTimeFormat('id-ID', { ...WIB, day: '2-digit', month: 'short', year: 'numeric' }).format(date);
};

export const shortDate = (iso) => {
    if (!iso) return '';
    const date = new Date(iso);
    if (Number.isNaN(date.getTime())) return '';
    return new Intl.DateTimeFormat('id-ID', { ...WIB, day: '2-digit', month: '2-digit' }).format(date);
};

export const clockNow = () => wibDateTime(new Date().toISOString());

// Warna tag mengikuti makna status, bukan nomor urut.
const TONE = {
    SUCCESS: 'ok', ACTIVE: 'ok', PAID: 'ok', HEALTHY: 'ok', CONFIGURED: 'ok', accept: 'ok',
    PENDING: 'warn', PROCESSING: 'warn', PENDING_PAYMENT: 'warn', MANUAL_PENDING: 'warn',
    SENDING: 'warn', UNKNOWN: 'warn', SCHEDULED: 'warn', DEGRADED: 'warn', UNTESTED: 'plain',
    FAILED: 'bad', BLOCKED: 'bad', MANUAL_FAILED: 'bad', FAILED_CONFIRMED: 'bad', DOWN: 'bad',
    EXPIRED: 'bad', CANCELLED: 'plain', INACTIVE: 'bad', NOT_CONFIGURED: 'bad', EXHAUSTED: 'bad',
    REFUND: 'info', REFUNDED: 'info', CREATED: 'plain', OPEN: 'warn', IN_PROGRESS: 'warn',
    RESOLVED: 'ok', CLOSED: 'plain',
};

export const tone = (status) => TONE[String(status || '').toUpperCase()] || 'plain';

export const statusLabel = {
    PENDING_PAYMENT: 'Menunggu pembayaran', PAID: 'Pembayaran diterima', PROCESSING: 'Sedang diproses',
    SUCCESS: 'Berhasil', FAILED: 'Pesanan gagal', EXPIRED: 'Pembayaran kedaluwarsa',
    CANCELLED: 'Pesanan dibatalkan', BLOCKED: 'Pesanan gagal', REFUND: 'Perlu pemeriksaan',
    REFUNDED: 'Perlu pemeriksaan', CREATED: 'Dibuat', SENDING: 'Dikirim', PENDING: 'Menunggu',
    UNKNOWN: 'Belum pasti', MANUAL_PENDING: 'Menunggu manual', MANUAL_FAILED: 'Manual gagal',
    FAILED_CONFIRMED: 'Gagal terkonfirmasi', SUCCESS_ATTEMPT: 'Berhasil',
    active: 'Aktif', scheduled: 'Terjadwal', expired: 'Berakhir', inactive: 'Nonaktif', exhausted: 'Kuota habis',
    OPEN: 'Menunggu', IN_PROGRESS: 'Diproses', RESOLVED: 'Selesai', CLOSED: 'Ditutup',
};

export const label = (status) => statusLabel[status] || statusLabel[String(status || '').toUpperCase()] || status || '—';

// Salin teks: clipboard API bisa ditolak browser, jadi ada jalan cadangan.
export async function copyText(text) {
    try {
        await navigator.clipboard.writeText(text);
        return true;
    } catch {
        const area = document.createElement('textarea');
        area.value = text;
        area.setAttribute('readonly', '');
        area.style.position = 'fixed';
        area.style.opacity = '0';
        document.body.appendChild(area);
        area.select();
        const ok = document.execCommand('copy');
        area.remove();
        return ok;
    }
}
