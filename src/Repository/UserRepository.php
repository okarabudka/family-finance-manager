<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Family;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;

/**
 * @extends ServiceEntityRepository<User>
 *
 * @implements UserProviderInterface<User>
 */
class UserRepository extends ServiceEntityRepository implements UserProviderInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, User::class);
    }

    /** @return User[] */
    public function findByFamily(Family $family): array
    {
        return $this->findBy(['family' => $family]);
    }

    public function findByTelegramChatId(int $chatId): ?User
    {
        return $this->findOneBy(['telegramChatId' => $chatId]);
    }

    public function loadUserByIdentifier(string $identifier): UserInterface
    {
        return $this->findOneBy(['email' => $identifier])
            ?? throw new UserNotFoundException("User not found: $identifier");
    }

    public function refreshUser(UserInterface $user): UserInterface
    {
        return $this->loadUserByIdentifier($user->getUserIdentifier());
    }

    public function supportsClass(string $class): bool
    {
        return User::class === $class || is_subclass_of($class, User::class);
    }
}
