<?php

declare(strict_types=1);

namespace Beziworld\Tpay\Auth;

use Beziworld\Tpay\Exceptions\ApiException;
use Beziworld\Tpay\TpayConfig;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;

/**
 * Obtains OAuth access tokens from POST /oauth/auth and caches them until shortly before expiry.
 */
class AccessTokenProvider
{
    /**
     * Seconds subtracted from the token lifetime to avoid using a token that expires in flight.
     */
    private const int EXPIRY_MARGIN = 120;

    public function __construct(
        private readonly HttpFactory $http,
        private readonly Cache $cache,
        private readonly TpayConfig $config,
    ) {}

    /**
     * @throws ApiException When the credentials are rejected or Tpay cannot be reached.
     */
    public function token(): string
    {
        $cached = $this->cache->get($this->cacheKey());

        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        [$token, $lifetime] = $this->requestToken();

        $this->cache->put($this->cacheKey(), $token, max(60, $lifetime - self::EXPIRY_MARGIN));

        return $token;
    }

    /**
     * Drops the cached token, e.g. after the API answered 401.
     */
    public function forget(): void
    {
        $this->cache->forget($this->cacheKey());
    }

    /**
     * @return array{0: string, 1: int}
     */
    private function requestToken(): array
    {
        try {
            $response = $this->http
                ->createPendingRequest()
                ->baseUrl($this->config->apiUrl())
                ->acceptJson()
                ->asForm()
                ->timeout($this->config->timeout)
                ->connectTimeout($this->config->connectTimeout)
                ->post('/oauth/auth', [
                    'client_id' => $this->config->clientId,
                    'client_secret' => $this->config->clientSecret,
                ]);
        } catch (ConnectionException $exception) {
            throw ApiException::transport($exception);
        }

        if ($response->status() === 401 || $response->status() === 403) {
            throw ApiException::authenticationFailed($response->status());
        }

        $payload = $response->json();

        if ($response->failed() || ! is_array($payload)) {
            throw is_array($payload)
                ? ApiException::fromResponse($payload, $response->status())
                : ApiException::unexpectedResponse($response->status());
        }

        $token = $payload['access_token'] ?? null;

        if (! is_string($token) || $token === '') {
            throw ApiException::unexpectedResponse($response->status());
        }

        $expiresIn = $payload['expires_in'] ?? null;

        return [$token, is_numeric($expiresIn) ? (int) $expiresIn : 7200];
    }

    private function cacheKey(): string
    {
        return sprintf(
            '%s:token:%s:%s',
            $this->config->cachePrefix,
            $this->config->sandbox ? 'sandbox' : 'production',
            hash('sha256', $this->config->clientId),
        );
    }
}
