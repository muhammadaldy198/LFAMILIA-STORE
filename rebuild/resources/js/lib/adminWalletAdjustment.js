/** UI-only input validation: Laravel remains authoritative for wallet ledger writes. */
export function walletAdjustmentError(amountInput, reasonInput) {
    const raw = String(amountInput ?? '').trim();
    const amount = Number(raw);
    if (!raw || !Number.isSafeInteger(amount) || amount === 0 || Math.abs(amount) > 1_000_000_000) {
        return 'Nominal harus bilangan bulat selain nol, antara -Rp1.000.000.000 dan Rp1.000.000.000.';
    }
    const reason = String(reasonInput ?? '').trim();
    if (reason.length < 3 || reason.length > 500) return 'Alasan harus terdiri dari 3 sampai 500 karakter.';
    return '';
}
