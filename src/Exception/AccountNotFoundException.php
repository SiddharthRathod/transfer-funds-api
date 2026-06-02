<?php

declare(strict_types=1);

namespace App\Exception;

class AccountNotFoundException extends \RuntimeException
{
    public static function withId(int $id): self
    {
        return new self(sprintf('Account with ID %d not found.', $id));
    }
}
