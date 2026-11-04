<?php

declare(strict_types=1);

namespace Beziworld\Tpay\Exceptions;

/**
 * Raised when an incoming notification is malformed or cannot be authenticated.
 */
final class InvalidNotification extends TpayException
{
    public static function missingField(string $field): self
    {
        return new self(sprintf('Tpay notification is missing the "%s" field.', $field));
    }

    public static function invalidChecksum(): self
    {
        return new self('Tpay notification md5sum is invalid.');
    }

    public static function missingSecurityCode(): self
    {
        return new self('Cannot check the notification md5sum: TPAY_SECURITY_CODE is not configured.');
    }

    public static function foreignMerchant(string $merchantId): self
    {
        return new self(sprintf('Tpay notification for merchant %s does not belong to this shop.', $merchantId));
    }

    public static function invalidJws(string $reason): self
    {
        return new self('Tpay notification JWS signature is invalid: ' . $reason);
    }
}
