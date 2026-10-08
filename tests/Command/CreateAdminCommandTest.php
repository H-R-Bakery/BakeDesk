<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class CreateAdminCommandTest extends KernelTestCase
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

    public function testCommandHashesPasswordAndAssignsAdminRole(): void
    {
        $application = new Application(self::getContainer()->get('kernel'));
        $application->setAutoExit(false);
        $commandTester = new CommandTester($application->find('app:create-admin'));
        $commandTester->execute([
            'email' => 'owner@example.test',
            '--password' => 'a very secure password',
        ]);

        self::assertSame(0, $commandTester->getStatusCode());
        $user = $this->entityManager->getRepository(User::class)->findOneBy(['email' => 'owner@example.test']);
        self::assertInstanceOf(User::class, $user);
        self::assertContains('ROLE_ADMIN', $user->getRoles());
        self::assertNotSame('a very secure password', $user->getPassword());
        self::assertTrue(self::getContainer()->get(UserPasswordHasherInterface::class)->isPasswordValid($user, 'a very secure password'));
    }
}
