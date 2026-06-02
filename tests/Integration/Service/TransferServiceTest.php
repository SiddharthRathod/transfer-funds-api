<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service;

use App\Entity\Account;
use App\Entity\Transfer;
use App\Exception\AccountNotFoundException;
use App\Exception\InsufficientFundsException;
use App\Exception\InvalidTransferException;
use App\Service\TransferService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class TransferServiceTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private TransferService $transferService;

    /** @var int[] Account IDs created per test, cleaned up in tearDown */
    private array $accountIds = [];

    protected function setUp(): void
    {
        self::bootKernel();

        $this->em              = static::getContainer()->get(EntityManagerInterface::class);
        $this->transferService = static::getContainer()->get(TransferService::class);
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

    private function freshBalance(int $accountId): string
    {
        $this->em->clear();

        return $this->em->find(Account::class, $accountId)->getBalance();
    }

    // -------------------------------------------------------------------------
    // Test 1: Successful transfer
    // -------------------------------------------------------------------------

    public function testSuccessfulTransfer(): void
    {
        $sender   = $this->createAccount('Alice', '500.00');
        $receiver = $this->createAccount('Bob', '100.00');

        $transfer = $this->transferService->transfer(
            $sender->getId(),
            $receiver->getId(),
            '200.00',
        );

        self::assertInstanceOf(Transfer::class, $transfer);
        self::assertNotNull($transfer->getId());
        self::assertSame(Transfer::STATUS_COMPLETED, $transfer->getStatus());
        self::assertStringStartsWith('TRX-', $transfer->getReference());
        self::assertSame('200.00', $transfer->getAmount());

        self::assertSame('300.00', $this->freshBalance($sender->getId()));
        self::assertSame('300.00', $this->freshBalance($receiver->getId()));
    }

    // -------------------------------------------------------------------------
    // Test 2: Insufficient balance
    // -------------------------------------------------------------------------

    public function testInsufficientBalanceThrows(): void
    {
        $sender   = $this->createAccount('Alice', '50.00');
        $receiver = $this->createAccount('Bob', '0.00');

        $this->expectException(InsufficientFundsException::class);
        $this->expectExceptionMessageMatches('/insufficient funds/i');

        $this->transferService->transfer(
            $sender->getId(),
            $receiver->getId(),
            '100.00',
        );
    }

    public function testInsufficientBalanceDoesNotMutateBalances(): void
    {
        $sender   = $this->createAccount('Alice', '50.00');
        $receiver = $this->createAccount('Bob', '200.00');

        try {
            $this->transferService->transfer(
                $sender->getId(),
                $receiver->getId(),
                '100.00',
            );
        } catch (InsufficientFundsException) {
        }

        self::assertSame('50.00', $this->freshBalance($sender->getId()));
        self::assertSame('200.00', $this->freshBalance($receiver->getId()));
    }

    // -------------------------------------------------------------------------
    // Test 3: Sender not found
    // -------------------------------------------------------------------------

    public function testSenderNotFound(): void
    {
        $receiver = $this->createAccount('Bob', '100.00');

        $this->expectException(AccountNotFoundException::class);
        $this->expectExceptionMessageMatches('/Account with ID 999999 not found/');

        $this->transferService->transfer(999999, $receiver->getId(), '10.00');
    }

    // -------------------------------------------------------------------------
    // Test 4: Receiver not found
    // -------------------------------------------------------------------------

    public function testReceiverNotFound(): void
    {
        $sender = $this->createAccount('Alice', '100.00');

        $this->expectException(AccountNotFoundException::class);
        $this->expectExceptionMessageMatches('/Account with ID 999999 not found/');

        $this->transferService->transfer($sender->getId(), 999999, '10.00');
    }

    // -------------------------------------------------------------------------
    // Test 5: Transfer to same account
    // -------------------------------------------------------------------------

    public function testTransferToSameAccountThrows(): void
    {
        $account = $this->createAccount('Alice', '500.00');

        $this->expectException(InvalidTransferException::class);
        $this->expectExceptionMessageMatches('/must be different/i');

        $this->transferService->transfer(
            $account->getId(),
            $account->getId(),
            '100.00',
        );
    }

    public function testZeroAmountIsRejected(): void
    {
        $sender   = $this->createAccount('Alice', '500.00');
        $receiver = $this->createAccount('Bob', '0.00');

        $this->expectException(InvalidTransferException::class);
        $this->expectExceptionMessageMatches('/greater than zero/i');

        $this->transferService->transfer($sender->getId(), $receiver->getId(), '0.00');
    }

    public function testNegativeAmountIsRejected(): void
    {
        $sender   = $this->createAccount('Alice', '500.00');
        $receiver = $this->createAccount('Bob', '0.00');

        $this->expectException(InvalidTransferException::class);

        $this->transferService->transfer($sender->getId(), $receiver->getId(), '-50.00');
    }

    // -------------------------------------------------------------------------
    // Test 6: Concurrent race condition
    //
    // Spawns two PHP child processes that simultaneously attempt to debit the
    // full sender balance (100.00). Redis distributed lock + pessimistic DB lock
    // must ensure exactly one succeeds and one fails — balance must never go negative.
    // -------------------------------------------------------------------------

    public function testConcurrentTransfersDoNotOverdraft(): void
    {
        $sender    = $this->createAccount('Race Sender', '100.00');
        $receiver1 = $this->createAccount('Race Receiver A', '0.00');
        $receiver2 = $this->createAccount('Race Receiver B', '0.00');

        $senderId    = $sender->getId();
        $receiver1Id = $receiver1->getId();
        $receiver2Id = $receiver2->getId();

        $this->em->clear();

        // Child processes bootstrap their own EntityManager + LockFactory manually:
        // - DsnParser (DBAL 4.x) for DB URL parsing
        // - ArrayAdapter for ORM cache (no Redis cache dependency)
        // - enableNativeLazyObjects(true) for PHP 8.4 (no LazyGhostTrait needed)
        // - RedisStore + LockFactory wired to the real Redis container
        $childScript = <<<'PHPSCRIPT'
<?php
require '/var/www/html/vendor/autoload.php';
$_SERVER['APP_ENV'] = 'test';
$_SERVER['APP_DEBUG'] = '1';
(new Symfony\Component\Dotenv\Dotenv())->bootEnv('/var/www/html/.env');

use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\RedisStore;

$config = ORMSetup::createAttributeMetadataConfiguration(
    ['/var/www/html/src/Entity'],
    true,
    null,
    new ArrayAdapter()
);
$config->enableNativeLazyObjects(true);
$config->setNamingStrategy(new \Doctrine\ORM\Mapping\UnderscoreNamingStrategy());

$baseUrl = $_ENV['DATABASE_URL'] ?? '';
$dbUrl   = preg_replace('#/([^/?]+)(\?|$)#', '/\1_test\2', $baseUrl);

$params     = (new DsnParser(['mysql' => 'pdo_mysql', 'postgresql' => 'pdo_pgsql']))->parse($dbUrl);
$connection = DriverManager::getConnection($params);
$em         = new EntityManager($connection, $config);

// Build LockFactory backed by the same Redis instance the app uses
$redisUrl = $_ENV['REDIS_URL'] ?? 'redis://redis:6379';
$parsed   = parse_url($redisUrl);
$redis    = new \Redis();
$redis->connect($parsed['host'] ?? 'redis', $parsed['port'] ?? 6379);

$lockFactory = new LockFactory(new RedisStore($redis));
$logger      = new \Psr\Log\NullLogger();
$service     = new App\Service\TransferService($em, $lockFactory, $logger);

try {
    $service->transfer(%d, %d, '100.00');
    echo 'ok';
} catch (App\Exception\InsufficientFundsException) {
    echo 'insufficient';
} catch (App\Exception\ConcurrentTransferException) {
    // Redis lock blocked the second request — counts as the expected rejection
    echo 'insufficient';
} catch (Throwable $e) {
    echo 'error:' . $e->getMessage();
}
PHPSCRIPT;

        $tmp1 = tempnam(sys_get_temp_dir(), 'trx_') . '.php';
        $tmp2 = tempnam(sys_get_temp_dir(), 'trx_') . '.php';
        file_put_contents($tmp1, sprintf($childScript, $senderId, $receiver1Id));
        file_put_contents($tmp2, sprintf($childScript, $senderId, $receiver2Id));

        $p1 = proc_open('php ' . $tmp1, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes1);
        $p2 = proc_open('php ' . $tmp2, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes2);

        $out1 = trim(stream_get_contents($pipes1[1]));
        $out2 = trim(stream_get_contents($pipes2[1]));

        proc_close($p1);
        proc_close($p2);
        unlink($tmp1);
        unlink($tmp2);

        $results = [$out1, $out2];
        sort($results);

        // One succeeds (Redis lock acquired + DB transfer done), one is rejected
        // (either ConcurrentTransferException or InsufficientFunds)
        self::assertSame(
            ['insufficient', 'ok'],
            $results,
            sprintf("Expected ['insufficient','ok']. Got: ['%s','%s']", $out1, $out2)
        );

        // Sender balance must be exactly 0.00 — never negative
        $finalBalance = $this->em
            ->getConnection()
            ->fetchOne('SELECT balance FROM accounts WHERE id = ?', [$senderId]);

        self::assertSame(
            '0.00',
            $finalBalance,
            "Sender balance must be 0.00 after one successful concurrent transfer. Got: $finalBalance"
        );
    }
}
