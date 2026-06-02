<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Account;
use App\Entity\Transfer;
use App\Exception\AccountNotFoundException;
use App\Exception\ConcurrentTransferException;
use App\Exception\InsufficientFundsException;
use App\Exception\InvalidTransferException;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\LockMode;
use Doctrine\DBAL\TransactionIsolationLevel;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Uid\Uuid;

class TransferService
{
    private readonly Connection $connection;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly LockFactory $lockFactory,
        private readonly LoggerInterface $logger,
    ) {
        $this->connection = $entityManager->getConnection();
    }

    public function transfer(int $fromAccountId, int $toAccountId, string $amount): Transfer
    {
        if (!is_numeric($amount) || bccomp($amount, '0.00', 2) <= 0) {
            throw InvalidTransferException::negativeOrZeroAmount($amount);
        }

        if ($fromAccountId === $toAccountId) {
            throw InvalidTransferException::sameAccount();
        }

        $lock = $this->lockFactory->createLock(
            'transfer-account-' . $fromAccountId,
            ttl: 10,
            autoRelease: false,
        );

        if (!$lock->acquire()) {
            $this->logger->warning('transfer.concurrent_rejected', [
                'fromAccountId' => $fromAccountId,
                'toAccountId'   => $toAccountId,
                'amount'        => $amount,
            ]);

            throw ConcurrentTransferException::forAccount($fromAccountId);
        }

        $this->logger->info('transfer.started', [
            'fromAccountId' => $fromAccountId,
            'toAccountId'   => $toAccountId,
            'amount'        => $amount,
        ]);

        try {
            if (!$this->connection->isTransactionActive()) {
                $this->connection->setTransactionIsolation(TransactionIsolationLevel::SERIALIZABLE);
            }

            $transfer = $this->entityManager->wrapInTransaction(function () use (
                $fromAccountId,
                $toAccountId,
                $amount,
            ): Transfer {
                [$firstId, $secondId] = $fromAccountId < $toAccountId
                    ? [$fromAccountId, $toAccountId]
                    : [$toAccountId, $fromAccountId];

                $this->lockAccount($firstId);
                $this->lockAccount($secondId);

                $sender = $this->entityManager->find(Account::class, $fromAccountId)
                    ?? throw AccountNotFoundException::withId($fromAccountId);

                $receiver = $this->entityManager->find(Account::class, $toAccountId)
                    ?? throw AccountNotFoundException::withId($toAccountId);

                if (!$sender->hasSufficientBalance($amount)) {
                    throw InsufficientFundsException::forAccount(
                        $sender->getId(),
                        $sender->getBalance(),
                        $amount,
                    );
                }

                $transfer = new Transfer(
                    fromAccount: $sender,
                    toAccount: $receiver,
                    amount: $amount,
                    reference: 'TRX-' . Uuid::v4()->toRfc4122(),
                );

                $sender->debit($amount);
                $receiver->credit($amount);
                $transfer->setStatus(Transfer::STATUS_COMPLETED);

                $this->entityManager->persist($transfer);

                return $transfer;
            });

            $this->logger->info('transfer.completed', [
                'reference'     => $transfer->getReference(),
                'fromAccountId' => $fromAccountId,
                'toAccountId'   => $toAccountId,
                'amount'        => $amount,
            ]);

            return $transfer;
        } catch (ConcurrentTransferException | AccountNotFoundException | InsufficientFundsException | InvalidTransferException $e) {
            $this->logger->warning('transfer.failed', [
                'fromAccountId' => $fromAccountId,
                'toAccountId'   => $toAccountId,
                'amount'        => $amount,
                'reason'        => $e->getMessage(),
            ]);

            throw $e;
        } catch (\Throwable $e) {
            $this->logger->error('transfer.error', [
                'fromAccountId' => $fromAccountId,
                'toAccountId'   => $toAccountId,
                'amount'        => $amount,
                'error'         => $e->getMessage(),
            ]);

            throw $e;
        } finally {
            $lock->release();
        }
    }

    private function lockAccount(int $id): void
    {
        $account = $this->entityManager
            ->createQueryBuilder()
            ->select('a')
            ->from(Account::class, 'a')
            ->where('a.id = :id')
            ->setParameter('id', $id)
            ->getQuery()
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->getOneOrNullResult();

        if ($account === null) {
            throw AccountNotFoundException::withId($id);
        }
    }
}
