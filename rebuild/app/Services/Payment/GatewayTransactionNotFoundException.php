<?php

namespace App\Services\Payment;

use RuntimeException;

/**
 * A gateway status inquiry definitively reported that no transaction
 * exists for the merchant reference. This is deliberately distinct from
 * an inquiry that merely failed (timeout, 5xx, unreachable): a failed
 * inquiry proves nothing about whether the transaction exists.
 */
class GatewayTransactionNotFoundException extends RuntimeException {}
