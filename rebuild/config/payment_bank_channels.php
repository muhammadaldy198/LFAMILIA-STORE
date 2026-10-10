<?php

// Provider-supported bank selections. Never generate VA numbers locally:
// Midtrans Snap and DOKU Checkout issue the actual payment instructions.
// Account-level activation remains controlled by the payment provider.
return [
    ['code' => 'va_bca', 'name' => 'BCA Virtual Account', 'sort_order' => 41, 'midtrans' => 'bca_va', 'doku' => 'VIRTUAL_ACCOUNT_BCA'],
    ['code' => 'va_bni', 'name' => 'BNI Virtual Account', 'sort_order' => 42, 'midtrans' => 'bni_va', 'doku' => 'VIRTUAL_ACCOUNT_BNI'],
    ['code' => 'va_bri', 'name' => 'BRI Virtual Account', 'sort_order' => 43, 'midtrans' => 'bri_va', 'doku' => 'VIRTUAL_ACCOUNT_BRI'],
    ['code' => 'va_mandiri', 'name' => 'Mandiri Virtual Account', 'sort_order' => 44, 'midtrans' => 'echannel', 'doku' => 'VIRTUAL_ACCOUNT_BANK_MANDIRI'],
    ['code' => 'va_permata', 'name' => 'Permata Virtual Account', 'sort_order' => 45, 'midtrans' => 'permata_va', 'doku' => 'VIRTUAL_ACCOUNT_BANK_PERMATA'],
    ['code' => 'va_cimb', 'name' => 'CIMB Niaga Virtual Account', 'sort_order' => 46, 'midtrans' => 'cimb_va', 'doku' => 'VIRTUAL_ACCOUNT_BANK_CIMB'],
];
