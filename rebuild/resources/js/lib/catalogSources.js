// Panel Sumber di editor produk: daftar mapping penyedia per nominal.
// Hanya memakai route yang sudah ada; tidak ada endpoint baru.

// Mapping dianggap terkunci harga bila admin memasang harga manual Digiflazz.
export const mappingIsLocked = mapping => Boolean(mapping?.is_manual_price);

export const mappingStatusLabel = mapping => {
    if (!mapping?.is_active) return mapping?.disabled_by_sync ? 'Nonaktif (sinkron)' : 'Nonaktif';
    if (mapping?.provider_code === 'VOUCHER_STOCK') {
        const counts = mapping.stock_counts || {};
        return `${Number(counts.available || 0)} kode siap`;
    }
    return 'Aktif';
};

// Urut prioritas: mapping aktif lebih dulu, lalu prioritas terkecil.
export const sortMappings = mappings => [...(mappings || [])].sort((a, b) => {
    if (Boolean(a.is_active) !== Boolean(b.is_active)) return a.is_active ? -1 : 1;
    return Number(a.priority || 0) - Number(b.priority || 0) || Number(a.id) - Number(b.id);
});

// Payload ubah mapping. Hanya field yang dikirim admin; harga manual tidak
// pernah dihapus diam-diam saat admin hanya menaikkan prioritas.
export const mappingPayload = (mapping, changes) => {
    const payload = {
        priority: Number(changes?.priority ?? mapping.priority ?? 0),
        is_active: Boolean(changes?.is_active ?? mapping.is_active),
    };
    if (mapping.provider_code === 'MANUAL') {
        // cost_idr wajib untuk MANUAL, tapi harus nilai yang tersimpan — bukan
        // store_cost_idr, yang bisa berbeda dan akan menimpa modal akuntansi.
        payload.cost_idr = Number(changes?.cost_idr ?? mapping.cost_idr ?? 0);
    }
    // Harga modal (store_cost_idr) boleh diubah dari tabel Sumber; server
    // memakai nilai ini untuk checkout dan batas harga.
    if (changes && Object.prototype.hasOwnProperty.call(changes, 'store_cost_idr')) {
        payload.store_cost_idr = Number(changes.store_cost_idr || 0);
    }
    if (mapping.provider_code === 'DIGIFLAZZ' && mappingIsLocked(mapping)) {
        // Kunci harga harus tetap menyala supaya cost_idr manual tidak dilepas.
        payload.is_manual_price = true;
    }
    return payload;
};

export const canSyncMapping = mapping => mapping?.provider_code === 'DIGIFLAZZ' && Boolean(mapping.external_sku);
