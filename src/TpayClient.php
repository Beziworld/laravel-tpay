<?php

declare(strict_types=1);

namespace Beziworld\Tpay;

use Beziworld\Tpay\Auth\AccessTokenProvider;
use Beziworld\Tpay\Data\BankGroup;
use Beziworld\Tpay\Data\PaymentChannel;
use Beziworld\Tpay\Data\Transaction;
use Beziworld\Tpay\Data\TransactionRequest;
use Beziworld\Tpay\Exceptions\ApiException;
use Beziworld\Tpay\Support\Amount;
use Beziworld\Tpay\Support\Arr;
use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Throwable;

/**
 * Client of the Tpay OpenAPI.
 */
class TpayClient
{
    /**
     * @param (Closure(): ?string)|null $defaultNotificationUrl Resolves the notification URL used when neither the request nor the configuration defines one.
     */
    public function __construct(
        private readonly HttpFactory $http,
        private readonly AccessTokenProvider $tokens,
        private readonly TpayConfig $config,
        private readonly ?Closure $defaultNotificationUrl = null,
    ) {}

    public function config(): TpayConfig
    {
        return $this->config;
    }

    /**
     * Creates a transaction. Redirect the payer to $transaction->paymentUrl unless
     * the payment was completed directly (e.g. BLIK level 0).
     */
    public function createTransaction(TransactionRequest $request): Transaction
    {
        $payer = ['email' => $request->payerEmail, 'name' => $request->payerName] + $request->payer;

        $body = [
            'amount' => (float) $request->amount,
            'description' => $request->description,
            'lang' => $request->lang ?? $this->config->lang,
            'payer' => $payer,
        ];

        if ($request->hiddenDescription !== null) {
            $body['hiddenDescription'] = $request->hiddenDescription;
        }

        $callbacks = $this->callbacks($request);

        if ($callbacks !== []) {
            $body['callbacks'] = $callbacks;
        }

        $pay = $request->payObject();

        if ($pay !== []) {
            $body['pay'] = $pay;
        }

        return Transaction::fromArray($this->call('POST', '/transactions', $body));
    }

    public function transaction(string $transactionId): Transaction
    {
        return Transaction::fromArray($this->call('GET', '/transactions/' . rawurlencode($transactionId)));
    }

    /**
     * Refunds a paid transaction, fully when $amount is null.
     */
    public function refund(string $transactionId, string|int|float|null $amount = null): Transaction
    {
        $body = $amount === null ? [] : ['amount' => Amount::toFloat($amount)];

        return Transaction::fromArray($this->call('POST', '/transactions/' . rawurlencode($transactionId) . '/refunds', $body));
    }

    /**
     * Cancels a pending transaction.
     */
    public function cancel(string $transactionId): Transaction
    {
        return Transaction::fromArray($this->call('POST', '/transactions/' . rawurlencode($transactionId) . '/cancel'));
    }

    /**
     * Pays an existing transaction with the given "pay" object (e.g. a new BLIK code after a failed attempt).
     *
     * @param array<string, mixed> $pay
     */
    public function pay(string $transactionId, array $pay): Transaction
    {
        return Transaction::fromArray($this->call('POST', '/transactions/' . rawurlencode($transactionId) . '/pay', $pay));
    }

    /**
     * Pays an existing transaction with a six-digit BLIK code.
     */
    public function payWithBlik(string $transactionId, string $blikCode): Transaction
    {
        return $this->pay($transactionId, ['groupId' => 150, 'blikPaymentData' => ['blikToken' => $blikCode]]);
    }

    /**
     * @return list<BankGroup>
     */
    public function bankGroups(bool $onlyOnline = false): array
    {
        $payload = $this->call('GET', '/transactions/bank-groups', $onlyOnline ? ['onlyOnline' => 'true'] : []);

        return $this->mapList(Arr::array($payload, 'groups'), BankGroup::fromArray(...));
    }

    /**
     * @return list<PaymentChannel>
     */
    public function channels(): array
    {
        return $this->mapList(Arr::array($this->call('GET', '/transactions/channels'), 'channels'), PaymentChannel::fromArray(...));
    }

    /**
     * Sends an authenticated request to any OpenAPI endpoint not covered by a dedicated method.
     *
     * @param array<string, mixed> $data Query parameters for GET, JSON body otherwise.
     * @return array<array-key, mixed>
     *
     * @throws ApiException
     */
    public function call(string $method, string $path, array $data = []): array
    {
        $response = $this->send($method, $path, $data);

        if ($response->status() === 401) {
            // The cached token may have been revoked or expired early: refresh it once.
            $this->tokens->forget();
            $response = $this->send($method, $path, $data);
        }

        $payload = $response->json();

        if (! is_array($payload)) {
            throw $response->failed()
                ? ApiException::fromResponse([], $response->status())
                : ApiException::unexpectedResponse($response->status());
        }

        if ($response->failed() || ($payload['result'] ?? null) === 'failed') {
            throw ApiException::fromResponse($payload, $response->status());
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    private function callbacks(TransactionRequest $request): array
    {
        $payerUrls = array_filter([
            'success' => $request->successUrl ?? $this->config->successUrl,
            'error' => $request->errorUrl ?? $this->config->errorUrl,
        ], static fn(?string $url): bool => $url !== null);

        $notification = array_filter([
            'url' => $request->notificationUrl ?? $this->config->notificationUrl ?? ($this->defaultNotificationUrl)?->__invoke(),
            'email' => $request->notificationEmail ?? $this->config->notificationEmail,
        ], static fn(?string $value): bool => $value !== null);

        return array_filter(
            ['payerUrls' => $payerUrls, 'notification' => $notification],
            static fn(array $section): bool => $section !== [],
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    private function send(string $method, string $path, array $data): Response
    {
        try {
            return $this->request()->send($method, ltrim($path, '/'), $method === 'GET'
                ? ['query' => $data]
                : ($data === [] ? [] : ['json' => $data]));
        } catch (ConnectionException $exception) {
            throw ApiException::transport($exception);
        }
    }

    private function request(): PendingRequest
    {
        $request = $this->http
            ->createPendingRequest()
            ->baseUrl($this->config->apiUrl() . '/')
            ->withToken($this->tokens->token())
            ->acceptJson()
            ->withUserAgent('beziworld-laravel-tpay/1.0')
            ->timeout($this->config->timeout)
            ->connectTimeout($this->config->connectTimeout);

        if ($this->config->retries > 0) {
            $request->retry(
                $this->config->retries + 1,
                $this->config->retryDelay,
                self::isSafeToRetry(...),
                throw: false,
            );
        }

        return $request;
    }

    /**
     * Retries only idempotent requests that failed at the transport level.
     *
     * A timed-out POST (transaction, refund, payment) may already have been
     * processed by Tpay; repeating it could charge or refund twice.
     */
    private static function isSafeToRetry(Throwable $exception, mixed $request = null, ?string $method = null): bool
    {
        return $exception instanceof ConnectionException && in_array($method, ['GET', 'HEAD'], true);
    }

    /**
     * @template T
     *
     * @param array<array-key, mixed> $items
     * @param callable(array<array-key, mixed>): T $factory
     * @return list<T>
     */
    private function mapList(array $items, callable $factory): array
    {
        $mapped = [];

        foreach ($items as $item) {
            if (is_array($item)) {
                $mapped[] = $factory($item);
            }
        }

        return $mapped;
    }
}
