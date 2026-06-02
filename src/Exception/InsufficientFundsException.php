<?php

declare(strict_types=1);

namespace App\Exception;

class InsufficientFundsException extends \RuntimeException
{
    public static function forAccount(int $accountId, string $balance, string $amount): self
    {
        return new self(sprintf(
            'Account %d has insufficient funds. Balance: %s, required: %s.',
            $accountId,
            $balance,
            $amount
        ));
    }
}
