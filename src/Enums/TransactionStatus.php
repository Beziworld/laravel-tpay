<?php

declare(strict_types=1);

namespace Beziworld\Tpay\Enums;

/**
 * Transaction status reported by the OpenAPI.
 */
enum TransactionStatus: string
{
    case Pending = 'pending';
    case Paid = 'paid';
    case Correct = 'correct';
    case Refund = 'refund';
    case Canceled = 'canceled';

    /**
     * Determines whether the payer completed the payment ("paid" or "correct").
     */
    public function isPaid(): bool
    {
        return $this === self::Paid || $this === self::Correct;
    }
}
