<?php

declare(strict_types=1);

namespace Beziworld\Tpay;

use Beziworld\Tpay\Exceptions\InvalidConfiguration;

/**
 * Validated, immutable view of the "tpay" configuration file.
 */
final readonly class TpayConfig
{
    public const string PRODUCTION_API = 'https://api.tpay.com';

    public const string SANDBOX_API = 'https://openapi.sandbox.tpay.com';

    public const string PRODUCTION_SECURE = 'https://secure.tpay.com';

    public const string SANDBOX_SECURE = 'https://secure.sandbox.tpay.com';

    public function __construct(
        public string $clientId,
        public string $clientSecret,
        public ?string $securityCode = null,
        public ?string $merchantId = null,
        public bool $sandbox = true,
        public string $lang = 'pl',
        public ?string $successUrl = null,
        public ?string $errorUrl = null,
        public ?string $notificationUrl = null,
        public ?string $notificationEmail = null,
        public int $timeout = 20,
        public int $connectTimeout = 5,
        public int $retries = 1,
        public int $retryDelay = 300,
        public ?string $cacheStore = null,
        public string $cachePrefix = 'tpay',
        public int $certificateTtl = 7200,
    ) {}

    /**
     * @param array<string, mixed> $config
     *
     * @throws InvalidConfiguration When the OpenAPI credentials are missing.
     */
    public static function fromArray(array $config): self
    {
        /** @var array<string, mixed> $http */
        $http = is_array($config['http'] ?? null) ? $config['http'] : [];
        /** @var array<string, mixed> $cache */
        $cache = is_array($config['cache'] ?? null) ? $config['cache'] : [];
        /** @var array<string, mixed> $notifications */
        $notifications = is_array($config['notifications'] ?? null) ? $config['notifications'] : [];

        return new self(
            clientId: self::string($config['client_id'] ?? null) ?? throw InvalidConfiguration::missing('client_id', 'TPAY_CLIENT_ID'),
            clientSecret: self::string($config['client_secret'] ?? null) ?? throw InvalidConfiguration::missing('client_secret', 'TPAY_CLIENT_SECRET'),
            securityCode: self::string($config['security_code'] ?? null),
            merchantId: self::string($config['merchant_id'] ?? null),
            sandbox: (bool) ($config['sandbox'] ?? true),
            lang: self::string($config['lang'] ?? null) ?? 'pl',
            successUrl: self::string($config['success_url'] ?? null),
            errorUrl: self::string($config['error_url'] ?? null),
            notificationUrl: self::string($config['notification_url'] ?? null),
            notificationEmail: self::string($config['notification_email'] ?? null),
            timeout: self::positiveInt($http['timeout'] ?? null, 20),
            connectTimeout: self::positiveInt($http['connect_timeout'] ?? null, 5),
            retries: max(0, is_numeric($http['retries'] ?? null) ? (int) $http['retries'] : 1),
            retryDelay: max(0, is_numeric($http['retry_delay'] ?? null) ? (int) $http['retry_delay'] : 300),
            cacheStore: self::string($cache['store'] ?? null),
            cachePrefix: self::string($cache['prefix'] ?? null) ?? 'tpay',
            certificateTtl: self::positiveInt($notifications['certificate_ttl'] ?? null, 7200),
        );
    }

    public function apiUrl(): string
    {
        return $this->sandbox ? self::SANDBOX_API : self::PRODUCTION_API;
    }

    /**
     * Origin that hosts the notification signing certificates for the configured environment.
     */
    public function secureUrl(): string
    {
        return $this->sandbox ? self::SANDBOX_SECURE : self::PRODUCTION_SECURE;
    }

    private static function string(mixed $value): ?string
    {
        if (is_int($value)) {
            return (string) $value;
        }

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private static function positiveInt(mixed $value, int $default): int
    {
        return is_numeric($value) && (int) $value > 0 ? (int) $value : $default;
    }
}
