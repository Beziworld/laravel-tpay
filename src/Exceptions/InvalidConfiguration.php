<?php

declare(strict_types=1);

namespace Beziworld\Tpay\Exceptions;

/**
 * Raised when the package configuration is incomplete.
 */
final class InvalidConfiguration extends TpayException
{
    public static function missing(string $key, string $env): self
    {
        return new self(sprintf('Tpay "%s" is not configured. Set %s in the environment.', $key, $env));
    }
}
