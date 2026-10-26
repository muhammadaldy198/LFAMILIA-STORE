<?php

// Store-payment methods verified in both hosted providers.
// The real payment code is created by Midtrans Snap / DOKU Checkout.
return [
    ['code' => 'retail_alfamart', 'name' => 'Alfa Group (Alfamart/Alfamidi)', 'sort_order' => 71, 'midtrans' => 'alfamart', 'doku' => 'ONLINE_TO_OFFLINE_ALFA'],
    ['code' => 'retail_indomaret', 'name' => 'Indomaret', 'sort_order' => 72, 'midtrans' => 'indomaret', 'doku' => 'ONLINE_TO_OFFLINE_INDOMARET'],
];
