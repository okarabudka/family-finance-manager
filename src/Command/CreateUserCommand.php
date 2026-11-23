<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Family;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[AsCommand(
    name: 'app:user:create',
    description: 'Creates a new user with a personal family.',
)]
class CreateUserCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserPasswordHasherInterface $passwordHasher,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('email', InputArgument::REQUIRED, 'User email address')
            ->addArgument('name', InputArgument::REQUIRED, 'User display name')
            ->addArgument('password', InputArgument::REQUIRED, 'User password (plain text)')
            ->addOption('admin', null, InputOption::VALUE_NONE, 'Grant ROLE_ADMIN to the user');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        /** @var string $email */
        $email = $input->getArgument('email');
        /** @var string $name */
        $name = $input->getArgument('name');
        /** @var string $plainPassword */
        $plainPassword = $input->getArgument('password');
        $isAdmin = (bool) $input->getOption('admin');

        $family = new Family();
        $family->setName($name."'s Family");

        $user = new User();
        $user->setEmail($email);
        $user->setName($name);
        $user->setFamily($family);

        $roles = ['ROLE_USER'];
        if ($isAdmin) {
            $roles[] = 'ROLE_ADMIN';
        }
        $user->setRoles($roles);

        $hashedPassword = $this->passwordHasher->hashPassword($user, $plainPassword);
        $user->setPassword($hashedPassword);

        $this->em->persist($family);
        $this->em->persist($user);
        $this->em->flush();

        $io->success(sprintf(
            'User "%s" (%s) created successfully with family "%s".%s',
            $name,
            $email,
            $family->getName(),
            $isAdmin ? ' Roles: ROLE_USER, ROLE_ADMIN.' : '',
        ));

        return Command::SUCCESS;
    }
}
