<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\Account;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;

class AccountFixtures extends Fixture
{
    public const ALICE_REFERENCE   = 'account-alice';
    public const BOB_REFERENCE     = 'account-bob';
    public const CHARLIE_REFERENCE = 'account-charlie';

    public function load(ObjectManager $manager): void
    {
        $alice = new Account('Alice', '1000.00');
        $manager->persist($alice);
        $this->addReference(self::ALICE_REFERENCE, $alice);

        $bob = new Account('Bob', '500.00');
        $manager->persist($bob);
        $this->addReference(self::BOB_REFERENCE, $bob);

        $charlie = new Account('Charlie', '2000.00');
        $manager->persist($charlie);
        $this->addReference(self::CHARLIE_REFERENCE, $charlie);

        $manager->flush();
    }
}
