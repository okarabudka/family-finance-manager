<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Import;

use App\Domain\Import\Parser\Mt940Parser;
use PHPUnit\Framework\TestCase;

class Mt940ParserTest extends TestCase
{
    private Mt940Parser $parser;
    private string $tmpFile;

    protected function setUp(): void
    {
        $this->parser  = new Mt940Parser();
        $this->tmpFile = tempnam(sys_get_temp_dir(), 'mt940_') . '.txt';
    }

    protected function tearDown(): void
    {
        if (file_exists($this->tmpFile)) {
            unlink($this->tmpFile);
        }
    }

    public function testSupportsOnlyMt940Format(): void
    {
        self::assertTrue($this->parser->supports('mt940'));
        self::assertFalse($this->parser->supports('csv_sparkasse'));
        self::assertFalse($this->parser->supports('csv'));
    }

    public function testSkipsLinesWithoutDebitCreditMark(): void
    {
        // MT940 :61: format: YYMMDD[YYMMDD]CD[R][currency]amount TTTT reference
        // D = debit (expense), C = credit (income)
        $content = ":20:STMT001\n" .
            ":25:DE89370400440532013000/EUR\n" .
            ":28C:00001/001\n" .
            ":60F:C250301EUR1000,00\n" .
            ":61:25030125030150,00NTRFREF1\n" .
            ":86:SVWZ+Supermarkt Einkauf\n" .
            ":62F:C250301EUR950,00\n";

        file_put_contents($this->tmpFile, $content);

        $result = $this->parser->parse($this->tmpFile);

        self::assertSame([], $result);
    }

    public function testParsesProperMt940DebitFormat(): void
    {
        // Correct MT940 :61: format: :61:VVVVVVBBBBBBCRREF
        // VV=value date, BB=booking date (opt), C=CD indicator, R=reversal(opt), amount, 4-char code, ref
        $content = ":20:STMT\n" .
            ":61:250301D50,00NTRFREF1\n" .
            ":86:SVWZ+Supermarkt Einkauf\n";

        file_put_contents($this->tmpFile, $content);

        $result = $this->parser->parse($this->tmpFile);

        self::assertCount(1, $result);
        self::assertSame('2025-03-01', $result[0]['date']);
        self::assertSame(-50.0, $result[0]['amount']);
        self::assertSame('Supermarkt Einkauf', $result[0]['description']);
    }

    public function testParsesCreditTransaction(): void
    {
        $content = ":20:STMT\n" .
            ":61:250315C2500,00NTRFREF2\n" .
            ":86:SVWZ+Gehalt Maerz\n";

        file_put_contents($this->tmpFile, $content);

        $result = $this->parser->parse($this->tmpFile);

        self::assertCount(1, $result);
        self::assertSame(2500.0, $result[0]['amount']);
        self::assertSame('Gehalt Maerz', $result[0]['description']);
    }

    public function testThrowsOnMissingFile(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->parser->parse('/tmp/nonexistent_mt940_xyz.txt');
    }

    public function testExtractsIbanFromDescription(): void
    {
        $content = ":20:STMT\n" .
            ":61:250301C100,00NTRFREF3\n" .
            ":86:IBAN+DE89370400440532013000+SVWZ+Transfer\n";

        file_put_contents($this->tmpFile, $content);

        $result = $this->parser->parse($this->tmpFile);

        self::assertCount(1, $result);
        self::assertSame('DE89370400440532013000', $result[0]['counterpartyIban']);
    }

    public function testReturnsEmptyArrayForFileWithNoTransactions(): void
    {
        file_put_contents($this->tmpFile, ":20:STMT\n:62F:C250301EUR1000,00\n");

        $result = $this->parser->parse($this->tmpFile);

        self::assertSame([], $result);
    }

    public function testParsesMultipleTransactions(): void
    {
        $content = ":20:STMT\n" .
            ":61:250301D50,00NTRFREF1\n" .
            ":86:SVWZ+Payment One\n" .
            ":61:250302C200,00NTRFREF2\n" .
            ":86:SVWZ+Income Two\n";

        file_put_contents($this->tmpFile, $content);

        $result = $this->parser->parse($this->tmpFile);

        self::assertCount(2, $result);
        self::assertSame(-50.0, $result[0]['amount']);
        self::assertSame(200.0, $result[1]['amount']);
    }
}
