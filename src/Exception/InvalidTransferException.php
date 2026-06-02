<?php

declare(strict_types=1);

namespace App\Exception;

class InvalidTransferException extends \RuntimeException
{
    public static function sameAccount(): self
    {
        return new self('Sender and receiver accounts must be different.');
    }

    public static function negativeOrZeroAmount(string $amount): self
    {
        return new self(sprintf('Transfer amount must be greater than zero. Got: %s.', $amount));
    }
}
