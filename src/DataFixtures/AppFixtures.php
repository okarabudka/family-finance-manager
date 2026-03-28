<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\Account;
use App\Entity\AccountType;
use App\Entity\Category;
use App\Entity\CategoryType;
use App\Entity\Family;
use App\Entity\User;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class AppFixtures extends Fixture
{
    public function __construct(
        private readonly UserPasswordHasherInterface $passwordHasher,
    ) {
    }

    public function load(ObjectManager $manager): void
    {
        $family = new Family();
        $family->setName('Моя семья')->setCurrency('EUR');
        $manager->persist($family);

        $user = new User();
        $user->setEmail('admin@example.com')
             ->setName('Администратор')
             ->setFamily($family)
             ->setRoles(['ROLE_USER', 'ROLE_ADMIN'])
             ->setPassword($this->passwordHasher->hashPassword($user, 'password'));
        $manager->persist($user);

        // Счета
        $bankAccount = new Account();
        $bankAccount->setName('Sparkasse')
                    ->setType(AccountType::Checking)
                    ->setIban('DE12345678901234567890')
                    ->setFamily($family)
                    ->setIsDefault(true);
        $manager->persist($bankAccount);

        $cashAccount = new Account();
        $cashAccount->setName('Наличные')
                    ->setType(AccountType::Cash)
                    ->setFamily($family);
        $manager->persist($cashAccount);

        $savingsAccount = new Account();
        $savingsAccount->setName('Накопления')
                       ->setType(AccountType::Savings)
                       ->setFamily($family);
        $manager->persist($savingsAccount);

        // Категории
        $categories = [
            ['Продукты',      CategoryType::Expense, '🛒'],
            ['Жильё',         CategoryType::Expense, '🏠'],
            ['Транспорт',     CategoryType::Expense, '🚗'],
            ['Рестораны',     CategoryType::Expense, '🍽️'],
            ['Здоровье',      CategoryType::Expense, '💊'],
            ['Одежда',        CategoryType::Expense, '👕'],
            ['Развлечения',   CategoryType::Expense, '🎭'],
            ['Образование',   CategoryType::Expense, '📚'],
            ['Зарплата',      CategoryType::Income,  '💰'],
            ['Прочие доходы', CategoryType::Income,  '🎁'],
        ];

        foreach ($categories as [$name, $type, $icon]) {
            $cat = new Category();
            $cat->setName($name)
                ->setType($type)
                ->setIcon($icon)
                ->setFamily($family)
                ->setIsSystem(true);
            $manager->persist($cat);
        }

        $manager->flush();
    }
}
