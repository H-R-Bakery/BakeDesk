<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[AsCommand(name: 'app:create-admin', description: 'Create a BakeDesk administrator account.')]
final class CreateAdminCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UserPasswordHasherInterface $passwordHasher,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('email', InputArgument::REQUIRED, 'Administrator email address')
            ->addOption('password', null, InputOption::VALUE_REQUIRED, 'Password (prefer the interactive prompt)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $email = mb_strtolower(trim((string) $input->getArgument('email')));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $output->writeln('<error>Enter a valid email address.</error>');

            return self::INVALID;
        }

        $repository = $this->entityManager->getRepository(User::class);
        if (null !== $repository->findOneBy(['email' => $email])) {
            $output->writeln('<error>An administrator with that email already exists.</error>');

            return self::FAILURE;
        }

        $password = (string) $input->getOption('password');
        if ('' === $password) {
            $question = (new Question('Password: '))->setHidden(true)->setHiddenFallback(false);
            $questionHelper = $this->getHelper('question');
            if (!$questionHelper instanceof QuestionHelper) {
                throw new \LogicException('The question helper is not available.');
            }
            $password = (string) $questionHelper->ask($input, $output, $question);
        }
        if (mb_strlen($password) < 12) {
            $output->writeln('<error>The password must contain at least 12 characters.</error>');

            return self::INVALID;
        }

        $user = (new User())->setEmail($email)->setRoles(['ROLE_ADMIN']);
        $user->setPassword($this->passwordHasher->hashPassword($user, $password));
        $this->entityManager->persist($user);
        $this->entityManager->flush();

        $output->writeln(sprintf('<info>Administrator %s created.</info>', $email));

        return self::SUCCESS;
    }
}
