<?php

declare(strict_types=1);

namespace Beziworld\Tpay\Tests;

use Beziworld\Tpay\Notifications\JwsVerifier;
use Beziworld\Tpay\TpayServiceProvider;
use Illuminate\Support\Facades\Http;
use OpenSSLAsymmetricKey;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    public const string SECURE = 'https://secure.sandbox.tpay.com';

    public const string CERT_URL = self::SECURE . '/x509/notifications-jws.pem';

    public const string ROOT_URL = self::SECURE . '/x509/tpay-jws-root.pem';

    /** @var array{rootPem: string, leafPem: string, leafKey: OpenSSLAsymmetricKey}|null */
    private static ?array $chain = null;

    protected function getPackageProviders($app): array
    {
        return [TpayServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('cache.default', 'array');
        $app['config']->set('tpay.client_id', 'client-id');
        $app['config']->set('tpay.client_secret', 'client-secret');
        $app['config']->set('tpay.security_code', 'security-code');
        $app['config']->set('tpay.merchant_id', '1010');
        $app['config']->set('tpay.sandbox', true);
        $app['config']->set('tpay.success_url', 'https://shop.test/success');
        $app['config']->set('tpay.error_url', 'https://shop.test/error');
        $app['config']->set('tpay.http.retry_delay', 0);
    }

    /**
     * Generates (once per run) a throw-away root CA and a signing certificate issued by it.
     *
     * @return array{rootPem: string, leafPem: string, leafKey: OpenSSLAsymmetricKey}
     */
    public static function chain(): array
    {
        if (self::$chain !== null) {
            return self::$chain;
        }

        $options = [
            'config' => __DIR__ . '/Fixtures/openssl.cnf',
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
            'digest_alg' => 'sha256',
        ];

        $rootKey = openssl_pkey_new($options);
        $leafKey = openssl_pkey_new($options);
        assert($rootKey instanceof OpenSSLAsymmetricKey && $leafKey instanceof OpenSSLAsymmetricKey);

        $rootCsr = openssl_csr_new(['commonName' => 'Test Tpay Root'], $rootKey, $options);
        $root = openssl_csr_sign($rootCsr, null, $rootKey, 1, $options + ['x509_extensions' => 'v3_ca']);
        $leafCsr = openssl_csr_new(['commonName' => 'Test Tpay Notifications'], $leafKey, $options);
        $leaf = openssl_csr_sign($leafCsr, $root, $rootKey, 1, $options + ['x509_extensions' => 'v3_leaf']);

        openssl_x509_export($root, $rootPem);
        openssl_x509_export($leaf, $leafPem);

        return self::$chain = ['rootPem' => $rootPem, 'leafPem' => $leafPem, 'leafKey' => $leafKey];
    }

    /**
     * Produces a detached JWS for the given raw body, signed with the given (or the test) key.
     */
    public static function jws(string $body, ?OpenSSLAsymmetricKey $key = null, string $x5u = self::CERT_URL): string
    {
        $header = JwsVerifier::base64UrlEncode((string) json_encode(['alg' => 'RS256', 'x5u' => $x5u]));
        openssl_sign($header . '.' . JwsVerifier::base64UrlEncode($body), $signature, $key ?? self::chain()['leafKey'], OPENSSL_ALGO_SHA256);

        return $header . '..' . JwsVerifier::base64UrlEncode($signature);
    }

    /**
     * Fakes the certificate endpoints together with any additional stubs.
     *
     * @param array<string, mixed> $stubs
     */
    public static function fakeCertificates(array $stubs = []): void
    {
        Http::fake($stubs + [
            self::CERT_URL => Http::response(self::chain()['leafPem']),
            self::ROOT_URL => Http::response(self::chain()['rootPem']),
        ]);
    }

    /**
     * Builds the form fields of a notification with a valid md5sum.
     *
     * @param array<string, string> $overrides
     * @return array<string, string>
     */
    public static function notificationFields(array $overrides = []): array
    {
        $fields = array_merge([
            'id' => '1010',
            'tr_id' => 'TR-BRA-KGZK0X',
            'tr_date' => '2026-11-04 12:00:00',
            'tr_crc' => 'order-1001',
            'tr_amount' => '123.45',
            'tr_paid' => '123.45',
            'tr_desc' => 'Zamówienie 1001',
            'tr_status' => 'TRUE',
            'tr_error' => 'none',
            'tr_email' => 'jan@example.com',
            'test_mode' => '1',
        ], $overrides);

        $fields['md5sum'] ??= md5($fields['id'] . $fields['tr_id'] . $fields['tr_amount'] . $fields['tr_crc'] . 'security-code');

        return $fields;
    }
}
