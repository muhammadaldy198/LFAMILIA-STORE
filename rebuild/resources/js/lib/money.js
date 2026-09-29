export const rupiah = (value) => 'Rp' + new Intl.NumberFormat('id-ID').format(BigInt(value ?? 0));
