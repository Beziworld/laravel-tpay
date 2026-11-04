<?php

declare(strict_types=1);

namespace Beziworld\Tpay\Data;

use Beziworld\Tpay\Support\Arr;

/**
 * Payment group returned by GET /transactions/bank-groups.
 */
final readonly class BankGroup
{
    /**
     * @param list<int> $channelIds
     */
    public function __construct(
        public int $id,
        public string $name,
        public ?string $imageUrl,
        public array $channelIds,
        public ?int $mainChannelId,
    ) {}

    /**
     * @param array<array-key, mixed> $item
     */
    public static function fromArray(array $item): self
    {
        $channels = array_values(array_filter(Arr::array($item, 'availablePaymentChannels'), is_numeric(...)));

        return new self(
            id: Arr::int($item, 'id') ?? 0,
            name: Arr::string($item, 'name') ?? '',
            imageUrl: Arr::string($item, 'img'),
            channelIds: array_map(intval(...), $channels),
            mainChannelId: Arr::int($item, 'mainChannel'),
        );
    }
}
