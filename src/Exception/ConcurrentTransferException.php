<?php

declare(strict_types=1);

namespace App\Exception;

class ConcurrentTransferException extends \RuntimeException
{
    public static function forAccount(int $accountId): self
    {
        return new self(
            sprintf('Another transfer is already in progress for account %d.', $accountId)
        );
    }
}
