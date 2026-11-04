<?php

declare(strict_types=1);

namespace Beziworld\Tpay\Data;

use Beziworld\Tpay\Support\Amount;
use InvalidArgumentException;

/**
 * Input of POST /transactions.
 *
 * "hiddenDescription" is the merchant identifier of the transaction (e.g.
 * the order number); Tpay echoes it in notifications as "tr_crc".
 * URLs and the language default to the package configuration when null.
 */
final readonly class TransactionRequest
{
    public string $amount;

    /**
     * @param array<string, mixed> $payer Additional payer fields: phone, address, code, city, country, taxId, ip, userAgent.
     * @param array<string, mixed> $pay Raw "pay" object; overrides groupId, channelId and blikCode when given.
     */
    public function __construct(
        string|int|float $amount,
        public string $description,
        public string $payerEmail,
        public string $payerName,
        public ?string $hiddenDescription = null,
        public ?string $lang = null,
        public ?string $successUrl = null,
        public ?string $errorUrl = null,
        public ?string $notificationUrl = null,
        public ?string $notificationEmail = null,
        public ?int $groupId = null,
        public ?int $channelId = null,
        public ?string $blikCode = null,
        public array $payer = [],
        public array $pay = [],
    ) {
        $this->amount = Amount::normalize($amount);

        if (trim($description) === '') {
            throw new InvalidArgumentException('Transaction description must not be empty.');
        }

        if ($blikCode !== null && preg_match('/^\d{6}$/', $blikCode) !== 1) {
            throw new InvalidArgumentException('BLIK code must consist of exactly six digits.');
        }
    }

    /**
     * The "pay" object of the request, or an empty array when the payer chooses the method on the Tpay page.
     *
     * @return array<string, mixed>
     */
    public function payObject(): array
    {
        if ($this->pay !== []) {
            return $this->pay;
        }

        if ($this->blikCode !== null) {
            return ['groupId' => 150, 'blikPaymentData' => ['blikToken' => $this->blikCode]];
        }

        return array_filter(
            ['groupId' => $this->groupId, 'channelId' => $this->channelId],
            static fn(?int $value): bool => $value !== null,
        );
    }
}
