<?php

declare(strict_types=1);

namespace App\DTO;

use Symfony\Component\Validator\Constraints as Assert;

final class TransferRequest
{
    public function __construct(
        #[Assert\NotBlank(message: 'fromAccountId is required.')]
        #[Assert\Type(type: 'integer', message: 'fromAccountId must be an integer.')]
        #[Assert\Positive(message: 'fromAccountId must be a positive integer.')]
        public readonly mixed $fromAccountId,

        #[Assert\NotBlank(message: 'toAccountId is required.')]
        #[Assert\Type(type: 'integer', message: 'toAccountId must be an integer.')]
        #[Assert\Positive(message: 'toAccountId must be a positive integer.')]
        public readonly mixed $toAccountId,

        #[Assert\NotBlank(message: 'amount is required.')]
        #[Assert\Type(type: 'numeric', message: 'amount must be numeric.')]
        #[Assert\Positive(message: 'amount must be greater than zero.')]
        public readonly mixed $amount,
    ) {}
}
