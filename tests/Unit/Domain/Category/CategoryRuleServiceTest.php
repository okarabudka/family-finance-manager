<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Category;

use App\Domain\Category\CategoryRuleService;
use App\Entity\Category;
use App\Entity\CategoryRule;
use App\Entity\Family;
use App\Repository\CategoryRuleRepository;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

class CategoryRuleServiceTest extends TestCase
{
    private CategoryRuleRepository&Stub $ruleRepo;
    private CategoryRuleService $service;
    private Family $family;

    protected function setUp(): void
    {
        $this->ruleRepo = $this->createStub(CategoryRuleRepository::class);
        $this->service  = new CategoryRuleService($this->ruleRepo);
        $this->family   = new Family();
    }

    public function testReturnsNullWhenNoRulesExist(): void
    {
        $this->ruleRepo->method('findByFamilyOrderedByPriority')->willReturn([]);

        $result = $this->service->guessCategory($this->family, 'groceries at REWE');

        self::assertNull($result);
    }

    public function testMatchesByKeyword(): void
    {
        $category = $this->createCategory('Groceries');
        $rule     = $this->createRule($category, ['rewe', 'lidl'], null);

        $this->ruleRepo->method('findByFamilyOrderedByPriority')->willReturn([$rule]);

        $result = $this->service->guessCategory($this->family, 'Payment at REWE Supermarkt');

        self::assertSame($category, $result);
    }

    public function testKeywordMatchIsCaseInsensitive(): void
    {
        $category = $this->createCategory('Groceries');
        $rule     = $this->createRule($category, ['REWE'], null);

        $this->ruleRepo->method('findByFamilyOrderedByPriority')->willReturn([$rule]);

        $result = $this->service->guessCategory($this->family, 'payment at rewe store');

        self::assertSame($category, $result);
    }

    public function testMatchesByIbanBeforeKeyword(): void
    {
        $groceriesCategory = $this->createCategory('Groceries');
        $rentCategory      = $this->createCategory('Rent');

        $ibanRule    = $this->createRule($rentCategory, [], 'DE89370400440532013000');
        $keywordRule = $this->createRule($groceriesCategory, ['rewe'], null);

        $this->ruleRepo->method('findByFamilyOrderedByPriority')
            ->willReturn([$ibanRule, $keywordRule]);

        // Description contains "rewe" but IBAN matches rent rule — IBAN wins
        $result = $this->service->guessCategory(
            $this->family,
            'rewe payment',
            'DE89370400440532013000',
        );

        self::assertSame($rentCategory, $result);
    }

    public function testReturnsNullWhenNoKeywordMatches(): void
    {
        $category = $this->createCategory('Groceries');
        $rule     = $this->createRule($category, ['rewe', 'lidl'], null);

        $this->ruleRepo->method('findByFamilyOrderedByPriority')->willReturn([$rule]);

        $result = $this->service->guessCategory($this->family, 'payment to unknown vendor');

        self::assertNull($result);
    }

    public function testReturnsNullWhenIbanDoesNotMatch(): void
    {
        $category = $this->createCategory('Utilities');
        $rule     = $this->createRule($category, [], 'DE89370400440532013000');

        $this->ruleRepo->method('findByFamilyOrderedByPriority')->willReturn([$rule]);

        $result = $this->service->guessCategory($this->family, 'payment', 'DE00000000000000000000');

        self::assertNull($result);
    }

    private function createCategory(string $name): Category
    {
        $category = new Category();
        $category->setName($name)->setIcon('💰')->setType(\App\Entity\CategoryType::Expense);

        return $category;
    }

    /** @param list<string> $keywords */
    private function createRule(Category $category, array $keywords, ?string $iban): CategoryRule
    {
        $rule = new CategoryRule();
        $rule->setCategory($category)
             ->setFamily($this->family)
             ->setKeywords($keywords)
             ->setCounterpartyIban($iban);

        return $rule;
    }
}
