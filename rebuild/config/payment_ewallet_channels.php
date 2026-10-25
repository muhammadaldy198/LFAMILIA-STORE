<?php

// Individual merchant-selectable e-wallet choices. Availability is gated by
// the Midtrans merchant account and the channel's admin activation setting.
return [
    ['code' => 'ewallet_gopay', 'name' => 'GoPay', 'sort_order' => 51, 'midtrans' => 'gopay'],
    ['code' => 'ewallet_shopeepay', 'name' => 'ShopeePay', 'sort_order' => 52, 'midtrans' => 'shopeepay'],
];
