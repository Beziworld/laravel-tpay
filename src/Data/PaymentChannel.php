<?php

declare(strict_types=1);

namespace Beziworld\Tpay\Data;

use Beziworld\Tpay\Support\Arr;

/**
 * Payment channel returned by GET /transactions/channels.
 */
final readonly class PaymentChannel
{
    /**
     * @param list<array<array-key, mixed>> $constraints Channel constraints such as minimal or maximal amount.
     */
    public function __construct(
        public int $id,
        public string $name,
        public ?string $fullName,
        public ?string $imageUrl,
        public bool $available,
        public bool $onlinePayment,
        public bool $instantRedirection,
        public array $constraints = [],
    ) {}

    /**
     * @param array<array-key, mixed> $item
     */
    public static function fromArray(array $item): self
    {
        return new self(
            id: Arr::int($item, 'id') ?? 0,
            name: Arr::string($item, 'name') ?? '',
            fullName: Arr::string($item, 'fullName'),
            imageUrl: Arr::string(Arr::array($item, 'image'), 'url'),
            available: Arr::bool($item, 'available') ?? false,
            onlinePayment: Arr::bool($item, 'onlinePayment') ?? false,
            instantRedirection: Arr::bool($item, 'instantRedirection') ?? false,
            constraints: array_values(array_filter(Arr::array($item, 'constraints'), is_array(...))),
        );
    }
}
