<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\DataFixtures\AppFixtures;

final class SecurityTest extends FunctionalTestCase
{
    public function testLoginPageIsPublic(): void
    {
        $this->client->request('GET', '/login');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('input[name="login_form[email]"]');
        self::assertSelectorExists('input[name="login_form[password]"]');
    }

    public function testProtectedPageRedirectsToLogin(): void
    {
        $this->client->request('GET', '/dashboard');

        self::assertResponseRedirects('/login');
    }

    public function testDemoUserCanLogIn(): void
    {
        $this->submitLogin(AppFixtures::DEMO_EMAIL, AppFixtures::DEMO_PASSWORD);

        self::assertResponseRedirects();
        $this->client->followRedirect();
        self::assertResponseRedirects('/dashboard');
        $this->client->followRedirect();
        self::assertResponseIsSuccessful();
    }

    public function testWrongPasswordIsRejected(): void
    {
        $this->submitLogin(AppFixtures::DEMO_EMAIL, 'wrong-password');

        self::assertResponseRedirects('/login');
        $this->client->followRedirect();
        self::assertSelectorExists('[role="alert"]');
    }

    private function submitLogin(string $email, string $password): void
    {
        $crawler = $this->client->request('GET', '/login');
        $form = $crawler->filter('form')->form([
            'login_form[email]' => $email,
            'login_form[password]' => $password,
        ]);
        $this->client->submit($form);
    }
}
