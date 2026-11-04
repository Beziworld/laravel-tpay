<?php

declare(strict_types=1);

namespace Beziworld\Tpay\Data;

use Beziworld\Tpay\Exceptions\InvalidNotification;
use Beziworld\Tpay\Support\Arr;

/**
 * Transaction notification delivered by Tpay as an application/x-www-form-urlencoded POST.
 */
final readonly class TransactionNotification
{
    /**
     * @param array<string, mixed> $payload All received fields, including optional card and token data.
     */
    public function __construct(
        public string $merchantId,
        public string $transactionTitle,
        public string $date,
        public string $crc,
        public string $amount,
        public string $paid,
        public string $description,
        public string $status,
        public ?string $email,
        public bool $testMode,
        public string $md5sum,
        public array $payload = [],
    ) {}

    /**
     * @param array<string, mixed> $payload
     *
     * @throws InvalidNotification When a mandatory field is missing.
     */
    public static function fromArray(array $payload): self
    {
        $required = static fn(string $field): string => Arr::string($payload, $field) ?? throw InvalidNotification::missingField($field);

        return new self(
            merchantId: $required('id'),
            transactionTitle: $required('tr_id'),
            date: Arr::string($payload, 'tr_date') ?? '',
            crc: Arr::string($payload, 'tr_crc') ?? '',
            amount: $required('tr_amount'),
            paid: Arr::string($payload, 'tr_paid') ?? '0.00',
            description: Arr::string($payload, 'tr_desc') ?? '',
            status: $required('tr_status'),
            email: Arr::string($payload, 'tr_email'),
            testMode: Arr::string($payload, 'test_mode') === '1',
            md5sum: $required('md5sum'),
            payload: $payload,
        );
    }

    /**
     * Checks md5sum = md5(id . tr_id . tr_amount . tr_crc . securityCode).
     */
    public function hasValidChecksum(string $securityCode): bool
    {
        $expected = md5(
            $this->merchantId
            . $this->transactionTitle
            . number_format((float) $this->amount, 2, '.', '')
            . $this->crc
            . $securityCode,
        );

        return hash_equals($expected, strtolower($this->md5sum));
    }

    /**
     * The payment was completed ("TRUE").
     */
    public function isPaid(): bool
    {
        return strtoupper($this->status) === 'TRUE';
    }

    /**
     * The transaction was fully refunded ("CHARGEBACK").
     */
    public function isChargeback(): bool
    {
        return strtoupper($this->status) === 'CHARGEBACK';
    }

    /**
     * Value of an optional field, e.g. "card_token" or "tr_channel".
     */
    public function get(string $field): ?string
    {
        return Arr::string($this->payload, $field);
    }
}
