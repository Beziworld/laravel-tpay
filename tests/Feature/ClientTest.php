<?php

declare(strict_types=1);

use Beziworld\Tpay\Data\TransactionRequest;
use Beziworld\Tpay\Enums\TransactionStatus;
use Beziworld\Tpay\Exceptions\ApiException;
use Beziworld\Tpay\Facades\Tpay;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

const TPAY_API = 'https://openapi.sandbox.tpay.com';

function fakeToken(array $stubs = []): void
{
    Http::fake($stubs + [
        TPAY_API . '/oauth/auth' => Http::response(['access_token' => 'access-1', 'expires_in' => 7200, 'token_type' => 'Bearer']),
    ]);
}

it('creates a transaction with an oauth token', function (): void {
    fakeToken([TPAY_API . '/transactions' => Http::response([
        'result' => 'success',
        'transactionId' => '01G6WAQZ',
        'title' => 'TR-BRA-KGZK0X',
        'status' => 'pending',
        'amount' => 123.45,
        'currency' => 'PLN',
        'hiddenDescription' => 'order-1001',
        'transactionPaymentUrl' => 'https://secure.sandbox.tpay.com/?title=TR-BRA-KGZK0X',
        'payments' => ['status' => 'pending', 'amountPaid' => 0],
    ])]);

    $transaction = Tpay::createTransaction(new TransactionRequest(
        amount: '123.45',
        description: 'Zamówienie 1001',
        payerEmail: 'jan@example.com',
        payerName: 'Jan Kowalski',
        hiddenDescription: 'order-1001',
        payer: ['phone' => '500100200'],
    ));

    expect($transaction->transactionId)->toBe('01G6WAQZ')
        ->and($transaction->title)->toBe('TR-BRA-KGZK0X')
        ->and($transaction->status)->toBe(TransactionStatus::Pending)
        ->and($transaction->paymentUrl)->toBe('https://secure.sandbox.tpay.com/?title=TR-BRA-KGZK0X')
        ->and($transaction->amount)->toBe(123.45)
        ->and($transaction->isPaid())->toBeFalse();

    Http::assertSent(fn(Request $request): bool => $request->url() === TPAY_API . '/oauth/auth'
        && $request['client_id'] === 'client-id'
        && $request['client_secret'] === 'client-secret'
        && $request->isForm());

    Http::assertSent(fn(Request $request): bool => $request->url() === TPAY_API . '/transactions'
        && $request->hasHeader('Authorization', 'Bearer access-1')
        && $request->data() === [
            'amount' => 123.45,
            'description' => 'Zamówienie 1001',
            'lang' => 'pl',
            'payer' => ['email' => 'jan@example.com', 'name' => 'Jan Kowalski', 'phone' => '500100200'],
            'hiddenDescription' => 'order-1001',
            'callbacks' => [
                'payerUrls' => ['success' => 'https://shop.test/success', 'error' => 'https://shop.test/error'],
                'notification' => ['url' => 'http://localhost/tpay/notification'],
            ],
        ]);
});

it('caches the access token between requests', function (): void {
    fakeToken(['*/transactions/*' => Http::response(['transactionId' => 'x', 'status' => 'paid'])]);

    Tpay::transaction('x');
    Tpay::transaction('x');

    Http::assertSentCount(3);
});

it('refreshes the token once after a 401', function (): void {
    Http::fake([
        TPAY_API . '/oauth/auth' => Http::sequence()
            ->push(['access_token' => 'old', 'expires_in' => 7200])
            ->push(['access_token' => 'new', 'expires_in' => 7200]),
        TPAY_API . '/transactions/x' => Http::sequence()
            ->push(['result' => 'failed'], 401)
            ->push(['transactionId' => 'x', 'status' => 'correct']),
    ]);

    expect(Tpay::transaction('x')->isPaid())->toBeTrue();

    Http::assertSent(fn(Request $request): bool => $request->hasHeader('Authorization', 'Bearer new'));
});

it('maps api validation errors', function (): void {
    fakeToken(['*/transactions' => Http::response([
        'result' => 'failed',
        'errors' => [['errorCode' => 'field_required', 'errorMessage' => 'Field is required', 'fieldName' => 'payer.email']],
    ], 400)]);

    try {
        Tpay::createTransaction(new TransactionRequest('1', 'd', 'e@x.pl', 'Jan'));
        test()->fail('Expected exception was not thrown.');
    } catch (ApiException $exception) {
        expect($exception->getMessage())->toBe('Tpay error: payer.email: Field is required')
            ->and($exception->httpStatus())->toBe(400)
            ->and($exception->errors())->toHaveCount(1);
    }
});

it('reports rejected credentials', function (): void {
    Http::fake([TPAY_API . '/oauth/auth' => Http::response(['error' => 'invalid_client'], 401)]);

    expect(fn() => Tpay::transaction('x'))->toThrow(ApiException::class, 'rejected the OpenAPI credentials');
});

it('refunds, cancels and pays transactions', function (): void {
    fakeToken(['*/transactions/*' => Http::response(['result' => 'success', 'transactionId' => 'x', 'status' => 'refund'])]);

    Tpay::refund('x', '10,5');
    Tpay::refund('x');
    Tpay::cancel('x');
    Tpay::payWithBlik('x', '123456');

    Http::assertSent(fn(Request $request): bool => $request->url() === TPAY_API . '/transactions/x/refunds' && $request->data() === ['amount' => 10.5]);
    Http::assertSent(fn(Request $request): bool => $request->url() === TPAY_API . '/transactions/x/refunds' && $request->data() === []);
    Http::assertSent(fn(Request $request): bool => $request->url() === TPAY_API . '/transactions/x/cancel');
    Http::assertSent(fn(Request $request): bool => $request->url() === TPAY_API . '/transactions/x/pay'
        && $request->data() === ['groupId' => 150, 'blikPaymentData' => ['blikToken' => '123456']]);
});

it('lists bank groups and channels', function (): void {
    fakeToken([
        TPAY_API . '/transactions/bank-groups?onlyOnline=true' => Http::response(['groups' => [
            ['id' => 102, 'name' => 'Pekao', 'img' => 'https://x/p.png', 'availablePaymentChannels' => ['1', 2], 'mainChannel' => 1],
        ]]),
        TPAY_API . '/transactions/channels' => Http::response(['channels' => [
            ['id' => 64, 'name' => 'BLIK', 'fullName' => 'BLIK', 'image' => ['url' => 'https://x/b.png'], 'available' => true, 'onlinePayment' => true, 'instantRedirection' => false, 'constraints' => [['field' => 'amount', 'type' => 'min', 'value' => '1.00']]],
        ]]),
    ]);

    $groups = Tpay::bankGroups(onlyOnline: true);
    $channels = Tpay::channels();

    expect($groups[0]->channelIds)->toBe([1, 2])
        ->and($groups[0]->mainChannelId)->toBe(1)
        ->and($channels[0]->imageUrl)->toBe('https://x/b.png')
        ->and($channels[0]->available)->toBeTrue()
        ->and($channels[0]->constraints)->toHaveCount(1);
});

it('wraps connection errors after retrying', function (): void {
    $attempts = 0;
    Http::fake(function (Request $request) use (&$attempts) {
        if (str_ends_with($request->url(), '/oauth/auth')) {
            return Http::response(['access_token' => 't', 'expires_in' => 7200]);
        }

        $attempts++;

        throw new ConnectionException('timeout');
    });

    expect(fn() => Tpay::transaction('x'))->toThrow(ApiException::class, 'Could not connect to Tpay')
        ->and($attempts)->toBe(2);
});
