<?php

declare(strict_types=1);

namespace Beziworld\Tpay\Exceptions;

use Throwable;

/**
 * Raised when Tpay rejects a request or cannot be reached.
 *
 * Field level errors returned by the API ("errors" array with errorCode,
 * errorMessage and fieldName) are available through errors().
 */
final class ApiException extends TpayException
{
    /**
     * @param array<array-key, mixed> $payload
     */
    public function __construct(
        string $message,
        private readonly ?int $httpStatus = null,
        private readonly array $payload = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $httpStatus ?? 0, $previous);
    }

    /**
     * @param array<array-key, mixed> $payload
     */
    public static function fromResponse(array $payload, int $httpStatus): self
    {
        $messages = [];
        $errors = $payload['errors'] ?? null;

        if (is_array($errors)) {
            foreach ($errors as $error) {
                if (! is_array($error)) {
                    continue;
                }

                $text = is_string($error['errorMessage'] ?? null) ? $error['errorMessage'] : null;
                $field = is_string($error['fieldName'] ?? null) && $error['fieldName'] !== '' ? $error['fieldName'] : null;

                if ($text !== null) {
                    $messages[] = $field !== null ? "{$field}: {$text}" : $text;
                }
            }
        }

        if ($messages === [] && is_string($payload['message'] ?? null)) {
            $messages[] = $payload['message'];
        }

        $description = $messages === [] ? sprintf('request failed with HTTP status %d', $httpStatus) : implode('; ', $messages);

        return new self('Tpay error: ' . $description, $httpStatus, $payload);
    }

    public static function transport(Throwable $previous): self
    {
        return new self('Could not connect to Tpay: ' . $previous->getMessage(), previous: $previous);
    }

    public static function unexpectedResponse(int $httpStatus): self
    {
        return new self('Tpay returned an unexpected response.', $httpStatus);
    }

    public static function authenticationFailed(int $httpStatus): self
    {
        return new self('Tpay rejected the OpenAPI credentials.', $httpStatus);
    }

    public function httpStatus(): ?int
    {
        return $this->httpStatus;
    }

    /**
     * @return array<array-key, mixed>
     */
    public function payload(): array
    {
        return $this->payload;
    }

    /**
     * @return list<array<array-key, mixed>>
     */
    public function errors(): array
    {
        $errors = $this->payload['errors'] ?? [];

        return is_array($errors) ? array_values(array_filter($errors, is_array(...))) : [];
    }
}
