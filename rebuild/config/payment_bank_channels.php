<?php

// Verified payment method type identifiers for Midtrans Snap and hosted DOKU Checkout.
// Provider activation is separate. New methods stay disabled until an admin tests them.
// A null provider code means the official Checkout/Snap list does not advertise that method.
return [
    ['code' => 'va_bca', 'name' => 'BCA Virtual Account', 'sort_order' => 41, 'midtrans' => 'bca_va', 'doku' => 'VIRTUAL_ACCOUNT_BCA'],
    ['code' => 'va_bni', 'name' => 'BNI Virtual Account', 'sort_order' => 42, 'midtrans' => 'bni_va', 'doku' => 'VIRTUAL_ACCOUNT_BNI'],
    ['code' => 'va_bri', 'name' => 'BRI Virtual Account', 'sort_order' => 43, 'midtrans' => 'bri_va', 'doku' => 'VIRTUAL_ACCOUNT_BRI'],
    ['code' => 'va_mandiri', 'name' => 'Mandiri Bill Payment / VA', 'sort_order' => 44, 'midtrans' => 'echannel', 'doku' => 'VIRTUAL_ACCOUNT_BANK_MANDIRI'],
    ['code' => 'va_permata', 'name' => 'Permata Virtual Account', 'sort_order' => 45, 'midtrans' => 'permata_va', 'doku' => 'VIRTUAL_ACCOUNT_BANK_PERMATA'],
    ['code' => 'va_cimb', 'name' => 'CIMB Niaga Virtual Account', 'sort_order' => 46, 'midtrans' => 'cimb_va', 'doku' => 'VIRTUAL_ACCOUNT_BANK_CIMB'],
    ['code' => 'va_bsi', 'name' => 'BSI Virtual Account', 'sort_order' => 47, 'midtrans' => 'bsi_va', 'doku' => 'VIRTUAL_ACCOUNT_BANK_SYARIAH_MANDIRI', 'initially_active' => false],
    ['code' => 'va_danamon', 'name' => 'Danamon Virtual Account', 'sort_order' => 48, 'midtrans' => 'danamon_va', 'doku' => 'VIRTUAL_ACCOUNT_BANK_DANAMON', 'initially_active' => false],
    ['code' => 'va_seabank', 'name' => 'SeaBank Virtual Account', 'sort_order' => 49, 'midtrans' => 'seabank_va', 'doku' => null, 'initially_active' => false],
    ['code' => 'va_saqu', 'name' => 'Bank Saqu Virtual Account', 'sort_order' => 50, 'midtrans' => 'saqu_va', 'doku' => null, 'initially_active' => false],
    ['code' => 'va_doku', 'name' => 'DOKU Virtual Account', 'sort_order' => 51, 'midtrans' => null, 'doku' => 'VIRTUAL_ACCOUNT_DOKU', 'initially_active' => false],
    ['code' => 'va_btn', 'name' => 'BTN Virtual Account', 'sort_order' => 52, 'midtrans' => null, 'doku' => 'VIRTUAL_ACCOUNT_BTN', 'initially_active' => false],
    ['code' => 'va_bnc', 'name' => 'BNC Virtual Account', 'sort_order' => 53, 'midtrans' => null, 'doku' => 'VIRTUAL_ACCOUNT_BNC', 'initially_active' => false],
    ['code' => 'va_bss', 'name' => 'BSS Virtual Account', 'sort_order' => 54, 'midtrans' => null, 'doku' => 'VIRTUAL_ACCOUNT_BSS', 'initially_active' => false],
    ['code' => 'va_bjb', 'name' => 'BJB Virtual Account', 'sort_order' => 55, 'midtrans' => null, 'doku' => 'VIRTUAL_ACCOUNT_BJB', 'initially_active' => false],
    // DOKU publishes this exact mixed-case code. Do not upper-case it.
    ['code' => 'va_sinarmas', 'name' => 'Sinarmas Virtual Account', 'sort_order' => 56, 'midtrans' => null, 'doku' => 'VIRTUAL_ACCOUNT_Sinarmas', 'initially_active' => false],
];
