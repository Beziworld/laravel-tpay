<?php

declare(strict_types=1);

namespace Beziworld\Tpay\Notifications;

use Beziworld\Tpay\Exceptions\InvalidNotification;
use Beziworld\Tpay\TpayConfig;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;

/**
 * Downloads and caches the X.509 certificates used to sign Tpay notifications.
 */
class CertificateProvider
{
    public function __construct(
        private readonly HttpFactory $http,
        private readonly Cache $cache,
        private readonly TpayConfig $config,
    ) {}

    /**
     * Returns the PEM certificate published at the given URL.
     *
     * @throws InvalidNotification When the certificate cannot be downloaded.
     */
    public function get(string $url): string
    {
        $key = $this->config->cachePrefix . ':certificate:' . hash('sha256', $url);
        $cached = $this->cache->get($key);

        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        try {
            $response = $this->http
                ->createPendingRequest()
                ->timeout($this->config->timeout)
                ->connectTimeout($this->config->connectTimeout)
                ->withOptions(['allow_redirects' => false])
                ->get($url);
        } catch (ConnectionException $exception) {
            throw InvalidNotification::invalidJws('certificate could not be downloaded (' . $exception->getMessage() . ')');
        }

        $pem = $response->body();

        if (! $response->successful() || ! str_contains($pem, '-----BEGIN CERTIFICATE-----')) {
            throw InvalidNotification::invalidJws(sprintf('certificate at %s is unavailable', $url));
        }

        $this->cache->put($key, $pem, $this->config->certificateTtl);

        return $pem;
    }

    /**
     * Drops a cached certificate so that it is downloaded again, at most once per throttle window.
     *
     * Refreshing after a failed verification handles key rotation; the throttle
     * prevents forged notifications from forcing a download on every request.
     *
     * @return bool Whether the certificate was dropped.
     */
    public function refresh(string $url, int $throttleSeconds = 300): bool
    {
        $hash = hash('sha256', $url);

        if (! $this->cache->add($this->config->cachePrefix . ':certificate-refresh:' . $hash, true, $throttleSeconds)) {
            return false;
        }

        $this->cache->forget($this->config->cachePrefix . ':certificate:' . $hash);

        return true;
    }
}
