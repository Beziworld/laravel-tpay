<?php

declare(strict_types=1);

namespace Beziworld\Tpay\Events;

use Beziworld\Tpay\Data\TransactionNotification;

/**
 * Dispatched when an authenticated notification reports a full refund (tr_status CHARGEBACK).
 */
final readonly class TransactionChargedBack
{
    public function __construct(
        public TransactionNotification $notification,
    ) {}
}
