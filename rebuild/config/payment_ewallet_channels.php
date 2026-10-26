<?php

// Source: official Midtrans Snap enabled_payments and DOKU Checkout payment_method_types.
// Never assume merchant account activation. New customer-visible channels are opt-in.
return [
    ['code' => 'ewallet_gopay', 'name' => 'GoPay', 'sort_order' => 61, 'midtrans' => 'gopay', 'doku' => null],
    ['code' => 'ewallet_shopeepay', 'name' => 'ShopeePay', 'sort_order' => 62, 'midtrans' => 'shopeepay', 'doku' => 'EMONEY_SHOPEE_PAY'],
    ['code' => 'ewallet_ovo', 'name' => 'OVO', 'sort_order' => 63, 'midtrans' => 'ovo', 'doku' => 'EMONEY_OVO'],
    ['code' => 'ewallet_dana', 'name' => 'DANA', 'sort_order' => 64, 'midtrans' => 'dana', 'doku' => 'EMONEY_DANA'],
    ['code' => 'ewallet_linkaja', 'name' => 'LinkAja', 'sort_order' => 65, 'midtrans' => null, 'doku' => 'EMONEY_LINKAJA'],
    // DOKU Wallet needs an additional notification URL contract. Catalog only:
    // do not provision a selectable payment route before that callback is implemented.
    ['code' => 'ewallet_doku', 'name' => 'DOKU Wallet', 'sort_order' => 66, 'midtrans' => null, 'doku' => 'EMONEY_DOKU', 'requires_integration' => true],
];
