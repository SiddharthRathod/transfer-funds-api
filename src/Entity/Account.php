<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\AccountRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: AccountRepository::class)]
#[ORM\Table(name: 'accounts')]
class Account
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private string $name;

    #[ORM\Column(type: Types::DECIMAL, precision: 15, scale: 2)]
    private string $balance;

    public function __construct(string $name, string $initialBalance = '0.00')
    {
        $this->name    = $name;
        $this->balance = $initialBalance;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getBalance(): string
    {
        return $this->balance;
    }

    public function hasSufficientBalance(string $amount): bool
    {
        return bccomp($this->balance, $amount, 2) >= 0;
    }

    public function credit(string $amount): void
    {
        $this->balance = bcadd($this->balance, $amount, 2);
    }

    public function debit(string $amount): void
    {
        $this->balance = bcsub($this->balance, $amount, 2);
    }
}
