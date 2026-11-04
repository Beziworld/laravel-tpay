<?php

declare(strict_types=1);

namespace Beziworld\Tpay\Events;

use Beziworld\Tpay\Data\TransactionNotification;

/**
 * Dispatched for every authenticated Tpay notification, regardless of its status.
 */
final readonly class NotificationReceived
{
    public function __construct(
        public TransactionNotification $notification,
    ) {}
}
