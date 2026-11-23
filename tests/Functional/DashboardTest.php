<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use PHPUnit\Framework\Attributes\DataProvider;

final class DashboardTest extends FunctionalTestCase
{
    public function testDashboardRendersChartsWithDemoData(): void
    {
        $this->loginAsDemoUser();

        $crawler = $this->client->request('GET', '/dashboard');

        self::assertResponseIsSuccessful();
        self::assertCount(2, $crawler->filter('canvas[data-chart]'));

        $trend = json_decode((string) $crawler->filter('#trendChart')->attr('data-chart'), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($trend);
        self::assertSame('bar', $trend['type']);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function pages(): iterable
    {
        yield 'transactions' => ['/transactions'];
        yield 'accounts' => ['/accounts'];
        yield 'categories' => ['/categories'];
        yield 'budgets' => ['/budgets'];
        yield 'savings goals' => ['/savings-goals'];
        yield 'meters' => ['/meters'];
        yield 'imports' => ['/imports'];
        yield 'pending imports' => ['/imports/pending'];
        yield 'settings' => ['/settings'];
        yield 'admin' => ['/admin'];
    }

    #[DataProvider('pages')]
    public function testPageRendersForDemoUser(string $url): void
    {
        $this->loginAsDemoUser();

        $this->client->request('GET', $url);

        self::assertResponseIsSuccessful();
    }
}
