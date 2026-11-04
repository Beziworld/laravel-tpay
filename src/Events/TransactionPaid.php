<?php

declare(strict_types=1);

namespace Beziworld\Tpay\Events;

use Beziworld\Tpay\Data\TransactionNotification;

/**
 * Dispatched when an authenticated notification reports a completed payment (tr_status TRUE).
 *
 * Tpay may deliver the same notification more than once, so listeners must
 * be idempotent and should compare tr_paid with the order amount.
 */
final readonly class TransactionPaid
{
    public function __construct(
        public TransactionNotification $notification,
    ) {}
}
