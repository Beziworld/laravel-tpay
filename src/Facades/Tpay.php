<?php

declare(strict_types=1);

namespace Beziworld\Tpay\Facades;

use Beziworld\Tpay\TpayClient;
use Illuminate\Support\Facades\Facade;

/**
 * @method static \Beziworld\Tpay\Data\Transaction createTransaction(\Beziworld\Tpay\Data\TransactionRequest $request)
 * @method static \Beziworld\Tpay\Data\Transaction transaction(string $transactionId)
 * @method static \Beziworld\Tpay\Data\Transaction refund(string $transactionId, string|int|float|null $amount = null)
 * @method static \Beziworld\Tpay\Data\Transaction cancel(string $transactionId)
 * @method static \Beziworld\Tpay\Data\Transaction pay(string $transactionId, array<string, mixed> $pay)
 * @method static \Beziworld\Tpay\Data\Transaction payWithBlik(string $transactionId, string $blikCode)
 * @method static list<\Beziworld\Tpay\Data\BankGroup> bankGroups(bool $onlyOnline = false)
 * @method static list<\Beziworld\Tpay\Data\PaymentChannel> channels()
 * @method static array<array-key, mixed> call(string $method, string $path, array<string, mixed> $data = [])
 * @method static \Beziworld\Tpay\TpayConfig config()
 *
 * @see TpayClient
 */
final class Tpay extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return TpayClient::class;
    }
}
