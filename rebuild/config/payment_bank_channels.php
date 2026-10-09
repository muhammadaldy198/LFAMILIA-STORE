<?php

// Provider-supported bank selections. Never generate VA numbers locally:
// Midtrans Snap and DOKU Checkout issue the actual payment instructions.
// Account-level activation remains controlled by the payment provider.
return [
    ['code' => 'va_bca', 'name' => 'VA BCA', 'sort_order' => 21, 'midtrans' => 'bca_va', 'doku' => 'VIRTUAL_ACCOUNT_BCA'],
    ['code' => 'va_bni', 'name' => 'VA BNI', 'sort_order' => 22, 'midtrans' => 'bni_va', 'doku' => 'VIRTUAL_ACCOUNT_BNI'],
    ['code' => 'va_bri', 'name' => 'VA BRI', 'sort_order' => 23, 'midtrans' => 'bri_va', 'doku' => 'VIRTUAL_ACCOUNT_BRI'],
    ['code' => 'va_mandiri', 'name' => 'VA Mandiri', 'sort_order' => 24, 'midtrans' => 'echannel', 'doku' => 'VIRTUAL_ACCOUNT_BANK_MANDIRI'],
    ['code' => 'va_permata', 'name' => 'VA Permata', 'sort_order' => 25, 'midtrans' => 'permata_va', 'doku' => 'VIRTUAL_ACCOUNT_BANK_PERMATA'],
    ['code' => 'va_cimb', 'name' => 'VA CIMB Niaga', 'sort_order' => 26, 'midtrans' => 'cimb_va', 'doku' => 'VIRTUAL_ACCOUNT_BANK_CIMB'],
];
