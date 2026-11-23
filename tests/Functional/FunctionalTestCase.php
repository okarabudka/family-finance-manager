<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\DataFixtures\AppFixtures;
use App\Entity\User;
use App\Repository\UserRepository;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Runs against the demo data set (`make test` loads it into the test database).
 * Every test is wrapped in a transaction that DAMADoctrineTestBundle rolls back.
 */
abstract class FunctionalTestCase extends WebTestCase
{
    protected KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
    }

    protected function demoUser(): User
    {
        $user = static::getContainer()->get(UserRepository::class)->findOneBy(['email' => AppFixtures::DEMO_EMAIL]);
        self::assertInstanceOf(User::class, $user, 'Demo user missing: load the fixtures into the test database first.');

        return $user;
    }

    protected function loginAsDemoUser(): User
    {
        $user = $this->demoUser();
        $this->client->loginUser($user);

        return $user;
    }
}
