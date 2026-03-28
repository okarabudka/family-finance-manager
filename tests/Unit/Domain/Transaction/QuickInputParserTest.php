<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Transaction;

use App\Domain\Transaction\QuickInputParser;
use App\Domain\Transaction\QuickInputResult;
use PHPUnit\Framework\TestCase;

class QuickInputParserTest extends TestCase
{
    private QuickInputParser $parser;

    protected function setUp(): void
    {
        $this->parser = new QuickInputParser();
    }

    public function testReturnsNullForEmptyString(): void
    {
        self::assertNull($this->parser->parse(''));
        self::assertNull($this->parser->parse('   '));
    }

    public function testParsesAmountThenDescription(): void
    {
        $result = $this->parser->parse('50 groceries');
        self::assertInstanceOf(QuickInputResult::class, $result);
        self::assertSame('50.00', $result->amount);
        self::assertSame('groceries', $result->description);
    }

    public function testParsesDescriptionThenAmount(): void
    {
        $result = $this->parser->parse('groceries 50');
        self::assertInstanceOf(QuickInputResult::class, $result);
        self::assertSame('50.00', $result->amount);
        self::assertSame('groceries', $result->description);
    }

    public function testParsesDecimalWithDot(): void
    {
        $result = $this->parser->parse('150.50 restaurant');
        self::assertNotNull($result);
        self::assertSame('150.50', $result->amount);
    }

    public function testParsesDecimalWithComma(): void
    {
        $result = $this->parser->parse('150,50 restaurant');
        self::assertNotNull($result);
        self::assertSame('150.50', $result->amount);
    }

    public function testParsesThousandsSeparatedBySpace(): void
    {
        $result = $this->parser->parse('1 234.99 supermarket');
        self::assertNotNull($result);
        self::assertSame('1234.99', $result->amount);
    }

    public function testReturnsNullForTextOnly(): void
    {
        self::assertNull($this->parser->parse('groceries only'));
    }

    public function testParsesMultiWordDescription(): void
    {
        $result = $this->parser->parse('25 coffee shop downtown');
        self::assertNotNull($result);
        self::assertSame('25.00', $result->amount);
        self::assertSame('coffee shop downtown', $result->description);
    }

    public function testReturnsNullForZeroAmount(): void
    {
        // "0 groceries" should yield 0.0 amount which is treated as invalid
        $result = $this->parser->parse('0 groceries');
        self::assertNull($result);
    }
}
