<?php

declare(strict_types=1);

namespace Beziworld\Tpay\Notifications;

use Beziworld\Tpay\Exceptions\InvalidNotification;
use Beziworld\Tpay\TpayConfig;
use JsonException;

/**
 * Verifies the detached JWS signature (RFC 7515) of a Tpay notification.
 *
 * The X-JWS-Signature header has the form "<header>..<signature>": the
 * payload segment is empty and stands for base64url(raw request body). The
 * protected header names the signing certificate in "x5u". The verifier
 * accepts only certificates hosted under the Tpay secure origin of the
 * configured environment, requires them to be issued by the Tpay root CA
 * and finally checks the RS256 signature with the certificate public key.
 */
class JwsVerifier
{
    public const string HEADER = 'X-JWS-Signature';

    public function __construct(
        private readonly CertificateProvider $certificates,
        private readonly TpayConfig $config,
    ) {}

    /**
     * @throws InvalidNotification When the signature is missing, malformed or invalid.
     */
    public function verify(string $rawBody, ?string $signature): void
    {
        if ($signature === null || $signature === '') {
            throw InvalidNotification::invalidJws('the ' . self::HEADER . ' header is missing');
        }

        $parts = explode('.', $signature);

        if (count($parts) !== 3 || $parts[0] === '' || $parts[2] === '') {
            throw InvalidNotification::invalidJws('the signature is not a compact JWS');
        }

        [$encodedHeader, , $encodedSignature] = $parts;
        $header = $this->decodeHeader($encodedHeader);

        if (($header['alg'] ?? 'RS256') !== 'RS256') {
            throw InvalidNotification::invalidJws('unsupported algorithm');
        }

        $certificateUrl = $header['x5u'] ?? null;
        $trustedPrefix = $this->config->secureUrl() . '/x509/';

        if (! is_string($certificateUrl) || ! str_starts_with($certificateUrl, $trustedPrefix) || str_contains($certificateUrl, '..')) {
            throw InvalidNotification::invalidJws('the signing certificate is not hosted by Tpay');
        }

        $rootUrl = $trustedPrefix . 'tpay-jws-root.pem';
        $rawSignature = self::base64UrlDecode($encodedSignature);
        $signingInput = $encodedHeader . '.' . self::base64UrlEncode($rawBody);

        if ($this->check($signingInput, $rawSignature, $certificateUrl, $rootUrl)) {
            return;
        }

        // The signing certificate may have been rotated: download it again, but at most
        // once per throttle window so that forged requests cannot force a fetch each time.
        // The root CA is long-lived and is never evicted by a failed verification.
        if (! $this->certificates->refresh($certificateUrl) || ! $this->check($signingInput, $rawSignature, $certificateUrl, $rootUrl)) {
            throw InvalidNotification::invalidJws('signature verification failed');
        }
    }

    public static function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    public static function base64UrlDecode(string $data): string
    {
        $decoded = base64_decode(strtr($data, '-_', '+/'), true);

        if ($decoded === false) {
            throw InvalidNotification::invalidJws('malformed base64url segment');
        }

        return $decoded;
    }

    /**
     * Checks the certificate chain and the signature using the currently cached certificates.
     *
     * @phpstan-impure
     */
    private function check(string $signingInput, string $signature, string $certificateUrl, string $rootUrl): bool
    {
        $certificate = $this->certificates->get($certificateUrl);
        $root = $this->certificates->get($rootUrl);

        if (openssl_x509_verify($certificate, $root) !== 1 || ! self::isWithinValidity($certificate)) {
            return false;
        }

        $publicKey = openssl_pkey_get_public($certificate);

        if ($publicKey === false) {
            return false;
        }

        return openssl_verify($signingInput, $signature, $publicKey, OPENSSL_ALGO_SHA256) === 1;
    }

    /**
     * Checks the notBefore / notAfter dates of the certificate, which openssl_x509_verify() ignores.
     */
    private static function isWithinValidity(string $certificate): bool
    {
        $details = openssl_x509_parse($certificate);

        if ($details === false) {
            return false;
        }

        $now = time();
        $from = $details['validFrom_time_t'] ?? null;
        $to = $details['validTo_time_t'] ?? null;

        return is_int($from) && is_int($to) && $from <= $now && $now <= $to;
    }

    /**
     * @return array<array-key, mixed>
     */
    private function decodeHeader(string $encoded): array
    {
        try {
            $header = json_decode(self::base64UrlDecode($encoded), true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw InvalidNotification::invalidJws('the protected header is not valid JSON');
        }

        if (! is_array($header)) {
            throw InvalidNotification::invalidJws('the protected header is not a JSON object');
        }

        return $header;
    }
}
