<?php

declare(strict_types=1);

namespace Beziworld\Tpay\Data;

use Beziworld\Tpay\Enums\TransactionStatus;
use Beziworld\Tpay\Support\Arr;

/**
 * Transaction returned by POST /transactions and GET /transactions/{id}.
 */
final readonly class Transaction
{
    /**
     * @param array<array-key, mixed> $raw Complete API response for fields not mapped explicitly.
     */
    public function __construct(
        public string $transactionId,
        public ?string $title,
        public ?TransactionStatus $status,
        public ?string $result,
        public ?float $amount,
        public ?string $currency,
        public ?string $description,
        public ?string $hiddenDescription,
        public ?string $paymentUrl,
        public ?string $paymentStatus,
        public ?float $amountPaid,
        public array $raw = [],
    ) {}

    /**
     * @param array<array-key, mixed> $payload
     */
    public static function fromArray(array $payload): self
    {
        $status = Arr::string($payload, 'status');
        $payments = Arr::array($payload, 'payments');
        $amount = $payload['amount'] ?? null;
        $amountPaid = $payments['amountPaid'] ?? null;

        return new self(
            transactionId: Arr::string($payload, 'transactionId') ?? '',
            title: Arr::string($payload, 'title'),
            status: $status !== null ? TransactionStatus::tryFrom($status) : null,
            result: Arr::string($payload, 'result'),
            amount: is_numeric($amount) ? (float) $amount : null,
            currency: Arr::string($payload, 'currency'),
            description: Arr::string($payload, 'description'),
            hiddenDescription: Arr::string($payload, 'hiddenDescription'),
            paymentUrl: Arr::string($payload, 'transactionPaymentUrl'),
            paymentStatus: Arr::string($payments, 'status'),
            amountPaid: is_numeric($amountPaid) ? (float) $amountPaid : null,
            raw: $payload,
        );
    }

    public function isPaid(): bool
    {
        return $this->status?->isPaid() ?? false;
    }
}
