// Payload nominal katalog. Dipisah dari Catalog.vue supaya bisa diuji langsung:
// mengedit nama/grup/urutan/status TIDAK boleh mengubah harga tersimpan.
export const nominalMode = row => (row?.pricing_mode === 'SELL_PRICE' ? 'FIXED' : row ? 'MARKUP' : 'FIXED');

export const nominalPriceValue = row => (row?.pricing_mode === 'SELL_PRICE'
    ? row.sell_price_idr
    : (row?.sell_markup_percent ?? ''));

const pricingOf = form => ({
    mode: form.price_mode === 'FIXED' ? 'SELL_PRICE' : 'PERCENT',
    value: Number(form.price_value || 0),
});

// Bandingkan dengan nilai yang tersimpan: harga hanya dikirim bila admin
// benar-benar mengubahnya di form.
const pricingChanged = (row, form) => {
    const next = pricingOf(form);
    return next.mode !== (row.pricing_mode === 'SELL_PRICE' ? 'SELL_PRICE' : 'PERCENT')
        || next.value !== Number(nominalPriceValue(row) || 0);
};

export function nominalPayload(row, form, code) {
    const payload = {
        name: form.name,
        code: row?.code || code,
        group_name: form.group_name || null,
        sort_order: Number(form.sort_order || 0),
        is_active: Boolean(form.is_active),
        note: null,
    };
    if (!row) {
        // Nominal baru belum punya nilai tersimpan; server menurunkan sendiri.
        payload.nominal_value = null;
        Object.assign(payload, {
            pricing_mode: pricingOf(form).mode,
            sell_price_idr: form.price_mode === 'FIXED' ? pricingOf(form).value : null,
            sell_markup_percent: form.price_mode === 'MARKUP' ? pricingOf(form).value : null,
            margin_percent: 0,
        });
        return payload;
    }
    // Edit: field yang tidak diubah tidak dikirim, jadi nilai tersimpan utuh
    // (termasuk nominal_value, PRODUCT_MARGIN, dan margin_fixed_idr).
    if (pricingChanged(row, form)) {
        Object.assign(payload, {
            pricing_mode: pricingOf(form).mode,
            sell_price_idr: form.price_mode === 'FIXED' ? pricingOf(form).value : null,
            sell_markup_percent: form.price_mode === 'MARKUP' ? pricingOf(form).value : null,
            margin_percent: form.price_mode === 'MARKUP' ? pricingOf(form).value : null,
        });
    }
    return payload;
}
