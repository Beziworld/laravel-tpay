<?php

declare(strict_types=1);

use Beziworld\Tpay\Events\NotificationReceived;
use Beziworld\Tpay\Events\TransactionChargedBack;
use Beziworld\Tpay\Events\TransactionPaid;
use Beziworld\Tpay\Notifications\JwsVerifier;
use Beziworld\Tpay\Tests\TestCase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    Event::fake([NotificationReceived::class, TransactionPaid::class, TransactionChargedBack::class]);
    TestCase::fakeCertificates();
});

/**
 * Posts a form-encoded notification with an optional JWS header computed over the exact body.
 *
 * @param array<string, string> $fields
 */
function postNotification(array $fields, ?string $jws = 'auto'): Illuminate\Testing\TestResponse
{
    $body = http_build_query($fields);
    $headers = ['CONTENT_TYPE' => 'application/x-www-form-urlencoded'];

    if ($jws !== null) {
        $headers['HTTP_' . strtoupper(str_replace('-', '_', JwsVerifier::HEADER))] = $jws === 'auto' ? TestCase::jws($body) : $jws;
    }

    return test()->call('POST', '/tpay/notification', $fields, [], [], $headers, $body);
}

it('accepts an authentic paid notification and answers TRUE', function (): void {
    postNotification(TestCase::notificationFields())
        ->assertOk()
        ->assertContent('TRUE');

    Event::assertDispatched(NotificationReceived::class);
    Event::assertDispatched(TransactionPaid::class, fn(TransactionPaid $event): bool => $event->notification->crc === 'order-1001'
        && $event->notification->paid === '123.45');
    Event::assertNotDispatched(TransactionChargedBack::class);
});

it('dispatches chargebacks', function (): void {
    postNotification(TestCase::notificationFields(['tr_status' => 'CHARGEBACK']))->assertOk();

    Event::assertDispatched(TransactionChargedBack::class);
    Event::assertNotDispatched(TransactionPaid::class);
});

it('rejects a wrong md5sum', function (): void {
    postNotification(TestCase::notificationFields(['md5sum' => md5('forged')]))
        ->assertStatus(400)
        ->assertSee('FALSE - Tpay notification md5sum is invalid.');

    Event::assertNothingDispatched();
});

it('rejects notifications for another merchant', function (): void {
    postNotification(TestCase::notificationFields(['id' => '9999']))->assertStatus(400);
});

it('rejects a missing jws header', function (): void {
    postNotification(TestCase::notificationFields(), null)
        ->assertStatus(400)
        ->assertSee('header is missing');
});

it('rejects a body modified after signing', function (): void {
    $fields = TestCase::notificationFields();
    $jws = TestCase::jws(http_build_query($fields));
    $fields['tr_paid'] = '0.01';

    postNotification($fields, $jws)->assertStatus(400)->assertSee('signature verification failed');
});

it('rejects certificates hosted outside the tpay origin', function (string $x5u): void {
    $body = http_build_query(TestCase::notificationFields());

    postNotification(TestCase::notificationFields(), TestCase::jws($body, x5u: $x5u))
        ->assertStatus(400)
        ->assertSee('not hosted by Tpay');
})->with([
    'foreign host' => 'https://evil.example/x509/notifications-jws.pem',
    'look-alike host' => 'https://secure.sandbox.tpay.com.evil.example/cert.pem',
    'production cert in sandbox' => 'https://secure.tpay.com/x509/notifications-jws.pem',
]);

it('rejects signatures made with a key outside the tpay chain', function (): void {
    $options = ['config' => dirname(__DIR__) . '/Fixtures/openssl.cnf', 'private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA];
    $foreignKey = openssl_pkey_new($options);
    assert($foreignKey instanceof OpenSSLAsymmetricKey);
    $fields = TestCase::notificationFields();

    postNotification($fields, TestCase::jws(http_build_query($fields), $foreignKey))->assertStatus(400);
});

it('rejects a certificate that is not issued by the tpay root', function (): void {
    $options = ['config' => dirname(__DIR__) . '/Fixtures/openssl.cnf', 'private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA, 'digest_alg' => 'sha256'];
    $key = openssl_pkey_new($options);
    assert($key instanceof OpenSSLAsymmetricKey);
    $selfSigned = openssl_csr_sign(openssl_csr_new(['commonName' => 'Impostor'], $key, $options), null, $key, 1, $options + ['x509_extensions' => 'v3_leaf']);
    openssl_x509_export($selfSigned, $pem);

    Http::fake([
        TestCase::CERT_URL => Http::response($pem),
        TestCase::ROOT_URL => Http::response(TestCase::chain()['rootPem']),
    ]);
    app('cache')->store()->flush();

    $fields = TestCase::notificationFields();

    postNotification($fields, TestCase::jws(http_build_query($fields), $key))->assertStatus(400);
});

it('rejects malformed jws values', function (string $jws, string $reason): void {
    postNotification(TestCase::notificationFields(), $jws)->assertStatus(400)->assertSee($reason);
})->with([
    ['abc', 'not a compact JWS'],
    ['!!!..sig', 'malformed base64url'],
    [JwsVerifier::base64UrlEncode('not json') . '..c2ln', 'not valid JSON'],
    [JwsVerifier::base64UrlEncode('{"alg":"HS256","x5u":"https://secure.sandbox.tpay.com/x"}') . '..c2ln', 'unsupported algorithm'],
]);

it('caches downloaded certificates', function (): void {
    postNotification(TestCase::notificationFields())->assertOk();
    postNotification(TestCase::notificationFields())->assertOk();

    Http::assertSentCount(2);
});

it('can skip the jws check when explicitly disabled', function (): void {
    config()->set('tpay.notifications.verify_jws', false);

    postNotification(TestCase::notificationFields(), null)->assertOk();
});

it('fails closed when md5 verification is enabled without a security code', function (): void {
    config()->set('tpay.security_code', null);
    app()->forgetInstance(\Beziworld\Tpay\TpayConfig::class);
    app()->forgetInstance(\Beziworld\Tpay\Notifications\NotificationValidator::class);

    postNotification(TestCase::notificationFields())->assertStatus(400)->assertSee('TPAY_SECURITY_CODE');
});

it('refreshes cached certificates once when they were rotated', function (): void {
    $options = ['config' => dirname(__DIR__) . '/Fixtures/openssl.cnf', 'private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA, 'digest_alg' => 'sha256'];
    $staleKey = openssl_pkey_new($options);
    assert($staleKey instanceof OpenSSLAsymmetricKey);
    $stale = openssl_csr_sign(openssl_csr_new(['commonName' => 'Stale'], $staleKey, $options), null, $staleKey, 1, $options + ['x509_extensions' => 'v3_leaf']);
    openssl_x509_export($stale, $stalePem);
    app('cache')->store()->put('tpay:certificate:' . hash('sha256', TestCase::CERT_URL), $stalePem, 60);

    postNotification(TestCase::notificationFields())->assertOk();

    Http::assertSent(fn(Illuminate\Http\Client\Request $request): bool => $request->url() === TestCase::CERT_URL);
});

it('throttles certificate refreshes triggered by invalid signatures', function (): void {
    $options = ['config' => dirname(__DIR__) . '/Fixtures/openssl.cnf', 'private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA];
    $foreignKey = openssl_pkey_new($options);
    assert($foreignKey instanceof OpenSSLAsymmetricKey);
    $fields = TestCase::notificationFields();
    $jws = TestCase::jws(http_build_query($fields), $foreignKey);

    postNotification($fields, $jws)->assertStatus(400);
    postNotification($fields, $jws)->assertStatus(400);
    postNotification($fields, $jws)->assertStatus(400);

    Http::assertSentCount(3);   // leaf + root on the first request, one leaf refresh in total
});

it('acknowledges but ignores test mode payments in production', function (): void {
    config()->set('tpay.sandbox', false);
    config()->set('tpay.notifications.verify_jws', false);
    app()->forgetInstance(\Beziworld\Tpay\TpayConfig::class);

    postNotification(TestCase::notificationFields(['test_mode' => '1']), null)->assertOk()->assertContent('TRUE');

    Event::assertDispatched(NotificationReceived::class);
    Event::assertNotDispatched(TransactionPaid::class);
});

it('checks signatures against the raw body even when values have surrounding spaces', function (): void {
    postNotification(TestCase::notificationFields(['tr_crc' => ' order-1001 ']))->assertOk();

    Event::assertDispatched(TransactionPaid::class, fn(TransactionPaid $event): bool => $event->notification->crc === ' order-1001 ');
});

it('rejects certificates outside the x509 directory', function (): void {
    $body = http_build_query(TestCase::notificationFields());

    postNotification(TestCase::notificationFields(), TestCase::jws($body, x5u: TestCase::SECURE . '/uploads/cert.pem'))
        ->assertStatus(400)
        ->assertSee('not hosted by Tpay');
});
