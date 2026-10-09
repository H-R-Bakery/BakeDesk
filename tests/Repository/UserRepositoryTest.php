<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class UserRepositoryTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        $_ENV['DATABASE_URL'] = 'sqlite:///:memory:';
        $_SERVER['DATABASE_URL'] = 'sqlite:///:memory:';
        self::ensureKernelShutdown();
        self::bootKernel();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        (new SchemaTool($this->entityManager))->createSchema([$this->entityManager->getClassMetadata(User::class)]);
    }

    protected function tearDown(): void
    {
        $this->entityManager->getConnection()->close();
        self::ensureKernelShutdown();
    }

    public function testActiveOrderTakersAreFilteredAndSorted(): void
    {
        $anna = User::new('anna@example.test', 'Anna', employee: true)->setSortOrder(10);
        $alice = User::new('alice@example.test', 'Alice', employee: true)->setSortOrder(20);
        $aliceLater = User::new('alice-later@example.test', 'Alice', employee: true)->setSortOrder(20);
        $bob = User::new('bob@example.test', 'Bob', employee: false)->setSortOrder(10);
        $carol = User::new('carol@example.test', 'Carol', employee: true)->setActive(false)->setSortOrder(1);
        $dave = User::new('dave@example.test', 'Dave', employee: false)->setActive(false)->setSortOrder(2);

        foreach ([$anna, $alice, $aliceLater, $bob, $carol, $dave] as $user) {
            $this->entityManager->persist($user);
        }
        $this->entityManager->flush();

        $repository = self::getContainer()->get(UserRepository::class);
        $orderTakers = $repository->findActiveOrderTakers();

        self::assertSame(
            [$anna->getId(), $alice->getId(), $aliceLater->getId()],
            array_map(static fn (User $user): ?int => $user->getId(), $orderTakers),
        );
        self::assertSame(3, $repository->countAvailableForOrderEntry());
    }
}
