<?php

declare(strict_types=1);

use Beziworld\Tpay\Data\TransactionNotification;
use Beziworld\Tpay\Data\TransactionRequest;
use Beziworld\Tpay\Enums\TransactionStatus;
use Beziworld\Tpay\Exceptions\InvalidConfiguration;
use Beziworld\Tpay\Exceptions\InvalidNotification;
use Beziworld\Tpay\Support\Amount;
use Beziworld\Tpay\Tests\TestCase;
use Beziworld\Tpay\TpayConfig;

it('normalizes amounts', function (string|int|float $input, string $expected): void {
    expect(Amount::normalize($input))->toBe($expected);
})->with([
    ['12', '12.00'],
    [12, '12.00'],
    ['12,5', '12.50'],
    [19.99, '19.99'],
    ['0.01', '0.01'],
    ['007.10', '7.10'],
]);

it('rejects invalid amounts', function (string|int $input): void {
    expect(fn() => Amount::normalize($input))->toThrow(InvalidArgumentException::class);
})->with(['0', '0.00', '1.234', '-1', 'abc']);

it('resolves environment urls', function (): void {
    $sandbox = TpayConfig::fromArray(['client_id' => 'a', 'client_secret' => 'b']);
    $production = TpayConfig::fromArray(['client_id' => 'a', 'client_secret' => 'b', 'sandbox' => false]);

    expect($sandbox->apiUrl())->toBe('https://openapi.sandbox.tpay.com')
        ->and($sandbox->secureUrl())->toBe('https://secure.sandbox.tpay.com')
        ->and($production->apiUrl())->toBe('https://api.tpay.com')
        ->and($production->secureUrl())->toBe('https://secure.tpay.com');
});

it('requires credentials', function (): void {
    expect(fn() => TpayConfig::fromArray([]))->toThrow(InvalidConfiguration::class, 'TPAY_CLIENT_ID')
        ->and(fn() => TpayConfig::fromArray(['client_id' => 'a']))->toThrow(InvalidConfiguration::class, 'TPAY_CLIENT_SECRET');
});

it('builds the pay object', function (): void {
    expect((new TransactionRequest('10', 'd', 'e@x.pl', 'Jan'))->payObject())->toBe([])
        ->and((new TransactionRequest('10', 'd', 'e@x.pl', 'Jan', groupId: 102))->payObject())->toBe(['groupId' => 102])
        ->and((new TransactionRequest('10', 'd', 'e@x.pl', 'Jan', blikCode: '777123'))->payObject())
        ->toBe(['groupId' => 150, 'blikPaymentData' => ['blikToken' => '777123']])
        ->and(fn() => new TransactionRequest('10', 'd', 'e@x.pl', 'Jan', blikCode: '12'))->toThrow(InvalidArgumentException::class)
        ->and(fn() => new TransactionRequest('10', ' ', 'e@x.pl', 'Jan'))->toThrow(InvalidArgumentException::class);
});

it('checks the notification md5sum', function (): void {
    $notification = TransactionNotification::fromArray(TestCase::notificationFields());

    expect($notification->hasValidChecksum('security-code'))->toBeTrue()
        ->and($notification->hasValidChecksum('other'))->toBeFalse()
        ->and($notification->isPaid())->toBeTrue()
        ->and($notification->testMode)->toBeTrue()
        ->and($notification->get('tr_error'))->toBe('none');
});

it('formats the amount like tpay before hashing', function (): void {
    $fields = TestCase::notificationFields(['tr_amount' => '10', 'md5sum' => md5('1010TR-BRA-KGZK0X10.00order-1001security-code')]);

    expect(TransactionNotification::fromArray($fields)->hasValidChecksum('security-code'))->toBeTrue();
});

it('recognises chargebacks case-insensitively', function (): void {
    $notification = TransactionNotification::fromArray(TestCase::notificationFields(['tr_status' => 'chargeback']));

    expect($notification->isChargeback())->toBeTrue()->and($notification->isPaid())->toBeFalse();
});

it('requires mandatory notification fields', function (): void {
    $fields = TestCase::notificationFields();
    unset($fields['tr_id']);

    expect(fn() => TransactionNotification::fromArray($fields))->toThrow(InvalidNotification::class, 'tr_id');
});

it('classifies transaction statuses', function (): void {
    expect(TransactionStatus::Correct->isPaid())->toBeTrue()
        ->and(TransactionStatus::Pending->isPaid())->toBeFalse();
});
