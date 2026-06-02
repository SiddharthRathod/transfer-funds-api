<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller;

use App\Entity\Account;
use App\Entity\Transfer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class TransferControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;

    /** @var int[] IDs to clean up after each test */
    private array $accountIds = [];

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em     = static::getContainer()->get(EntityManagerInterface::class);
    }

    protected function tearDown(): void
    {
        if (!empty($this->accountIds)) {
            $this->em->createQuery(
                'DELETE FROM App\Entity\Transfer t
                 WHERE t.fromAccount IN (:ids) OR t.toAccount IN (:ids)'
            )->setParameter('ids', $this->accountIds)->execute();

            $this->em->createQuery(
                'DELETE FROM App\Entity\Account a WHERE a.id IN (:ids)'
            )->setParameter('ids', $this->accountIds)->execute();
        }

        $this->accountIds = [];
        $this->em->clear();

        parent::tearDown();
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function createAccount(string $name, string $balance): Account
    {
        $account = new Account($name, $balance);
        $this->em->persist($account);
        $this->em->flush();

        $this->accountIds[] = $account->getId();

        return $account;
    }

    private function post(array $payload): void
    {
        $this->client->request(
            method: 'POST',
            uri: '/api/transfers',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode($payload),
        );
    }

    private function responseData(): array
    {
        return json_decode($this->client->getResponse()->getContent(), true);
    }

    private function freshBalance(int $accountId): string
    {
        $this->em->clear();

        return $this->em->find(Account::class, $accountId)->getBalance();
    }

    private function findTransferByReference(string $reference): ?Transfer
    {
        return $this->em->getRepository(Transfer::class)->findOneBy(['reference' => $reference]);
    }

    // -------------------------------------------------------------------------
    // Test 1: Successful transfer
    // -------------------------------------------------------------------------

    public function testSuccessfulTransferReturns201(): void
    {
        $sender   = $this->createAccount('Alice', '500.00');
        $receiver = $this->createAccount('Bob', '100.00');

        $this->post([
            'fromAccountId' => $sender->getId(),
            'toAccountId'   => $receiver->getId(),
            'amount'        => '200.00',
        ]);

        self::assertResponseStatusCodeSame(201);

        $data = $this->responseData();
        self::assertTrue($data['success']);
        self::assertStringStartsWith('TRX-', $data['reference']);
        self::assertSame('completed', $data['status']);

        // Assert balances mutated correctly
        self::assertSame('300.00', $this->freshBalance($sender->getId()));
        self::assertSame('300.00', $this->freshBalance($receiver->getId()));

        // Assert Transfer record persisted
        $transfer = $this->findTransferByReference($data['reference']);
        self::assertNotNull($transfer);
        self::assertSame('200.00', $transfer->getAmount());
        self::assertSame(Transfer::STATUS_COMPLETED, $transfer->getStatus());
    }

    // -------------------------------------------------------------------------
    // Test 2: Insufficient funds
    // -------------------------------------------------------------------------

    public function testInsufficientFundsReturns422(): void
    {
        $sender   = $this->createAccount('Alice', '50.00');
        $receiver = $this->createAccount('Bob', '0.00');

        $this->post([
            'fromAccountId' => $sender->getId(),
            'toAccountId'   => $receiver->getId(),
            'amount'        => '100.00',
        ]);

        self::assertResponseStatusCodeSame(422);

        $data = $this->responseData();
        self::assertFalse($data['success']);
        self::assertStringContainsStringIgnoringCase('insufficient funds', $data['message']);

        // Balances must be unchanged
        self::assertSame('50.00', $this->freshBalance($sender->getId()));
        self::assertSame('0.00', $this->freshBalance($receiver->getId()));
    }

    // -------------------------------------------------------------------------
    // Test 3: Sender account not found
    // -------------------------------------------------------------------------

    public function testSenderNotFoundReturns404(): void
    {
        $receiver = $this->createAccount('Bob', '100.00');

        $this->post([
            'fromAccountId' => 999999,
            'toAccountId'   => $receiver->getId(),
            'amount'        => '10.00',
        ]);

        self::assertResponseStatusCodeSame(404);

        $data = $this->responseData();
        self::assertFalse($data['success']);
        self::assertStringContainsString('999999', $data['message']);
    }

    // -------------------------------------------------------------------------
    // Test 4: Receiver account not found
    // -------------------------------------------------------------------------

    public function testReceiverNotFoundReturns404(): void
    {
        $sender = $this->createAccount('Alice', '100.00');

        $this->post([
            'fromAccountId' => $sender->getId(),
            'toAccountId'   => 999999,
            'amount'        => '10.00',
        ]);

        self::assertResponseStatusCodeSame(404);

        $data = $this->responseData();
        self::assertFalse($data['success']);
        self::assertStringContainsString('999999', $data['message']);
    }

    // -------------------------------------------------------------------------
    // Test 5: Transfer to same account
    // -------------------------------------------------------------------------

    public function testSameAccountReturns400(): void
    {
        $account = $this->createAccount('Alice', '500.00');

        $this->post([
            'fromAccountId' => $account->getId(),
            'toAccountId'   => $account->getId(),
            'amount'        => '100.00',
        ]);

        self::assertResponseStatusCodeSame(400);

        $data = $this->responseData();
        self::assertFalse($data['success']);
        self::assertStringContainsStringIgnoringCase('different', $data['message']);
    }

    // -------------------------------------------------------------------------
    // Test 6: Invalid amount
    // -------------------------------------------------------------------------

    public function testZeroAmountReturns400(): void
    {
        $sender   = $this->createAccount('Alice', '500.00');
        $receiver = $this->createAccount('Bob', '0.00');

        $this->post([
            'fromAccountId' => $sender->getId(),
            'toAccountId'   => $receiver->getId(),
            'amount'        => '0',
        ]);

        self::assertResponseStatusCodeSame(400);

        $data = $this->responseData();
        self::assertFalse($data['success']);
    }

    public function testNegativeAmountReturns400(): void
    {
        $sender   = $this->createAccount('Alice', '500.00');
        $receiver = $this->createAccount('Bob', '0.00');

        $this->post([
            'fromAccountId' => $sender->getId(),
            'toAccountId'   => $receiver->getId(),
            'amount'        => '-50.00',
        ]);

        self::assertResponseStatusCodeSame(400);
        self::assertFalse($this->responseData()['success']);
    }

    public function testMissingFieldsReturns400(): void
    {
        $this->post(['fromAccountId' => 1]);

        self::assertResponseStatusCodeSame(400);

        $data = $this->responseData();
        self::assertFalse($data['success']);
        self::assertSame('Validation failed.', $data['message']);
        self::assertArrayHasKey('errors', $data);
        self::assertNotEmpty($data['errors']);
    }

    public function testInvalidJsonReturns400(): void
    {
        $this->client->request(
            method: 'POST',
            uri: '/api/transfers',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: 'not-json',
        );

        self::assertResponseStatusCodeSame(400);
        self::assertFalse($this->responseData()['success']);
    }

    // -------------------------------------------------------------------------
    // Test 7: Concurrent transfer attempt (HTTP-level 409 check)
    //
    // Simulates what happens when the Redis lock is already held by
    // manually acquiring the lock before the request.
    // -------------------------------------------------------------------------

    public function testConcurrentTransferReturns409(): void
    {
        $sender   = $this->createAccount('Alice', '500.00');
        $receiver = $this->createAccount('Bob', '0.00');

        // Pre-acquire the Redis lock to simulate a transfer already in progress
        $lockFactory = static::getContainer()->get('lock.transfer.factory');
        $lock = $lockFactory->createLock('transfer-account-' . $sender->getId(), 10);
        $lock->acquire();

        try {
            $this->post([
                'fromAccountId' => $sender->getId(),
                'toAccountId'   => $receiver->getId(),
                'amount'        => '100.00',
            ]);

            self::assertResponseStatusCodeSame(409);

            $data = $this->responseData();
            self::assertFalse($data['success']);
            self::assertStringContainsStringIgnoringCase(
                'Another transfer is already in progress',
                $data['message']
            );

            // Balance must be unchanged
            self::assertSame('500.00', $this->freshBalance($sender->getId()));
        } finally {
            $lock->release();
        }
    }

    // -------------------------------------------------------------------------
    // Health check
    // -------------------------------------------------------------------------

    public function testHealthEndpointReturns200(): void
    {
        $this->client->request('GET', '/health');

        self::assertResponseStatusCodeSame(200);

        $data = $this->responseData();
        self::assertSame('ok', $data['status']);
        self::assertSame('transfer-funds-api', $data['service']);
    }
}
