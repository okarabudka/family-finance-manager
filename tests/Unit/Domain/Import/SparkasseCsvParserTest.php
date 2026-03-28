<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Import;

use App\Domain\Import\Parser\SparkasseCsvParser;
use PHPUnit\Framework\TestCase;

class SparkasseCsvParserTest extends TestCase
{
    private SparkasseCsvParser $parser;

    protected function setUp(): void
    {
        $this->parser = new SparkasseCsvParser();
    }

    public function testSupportsOnlySparkasseFormat(): void
    {
        self::assertTrue($this->parser->supports('csv_sparkasse'));
        self::assertFalse($this->parser->supports('mt940'));
        self::assertFalse($this->parser->supports('csv'));
    }

    public function testParsesValidCsv(): void
    {
        $csv = implode("\n", [
            '"Auftragskonto";"Buchungstag";"Valutadatum";"Buchungstext";"Auftraggeber / Beguenstigter";"Verwendungszweck";"Glaeubiger ID";"Mandatsreferenz";"Kundenreferenz (End-to-End)";"Sammlerreferenz";"Lastschrift Ursprungsbetrag";"Auslagenersatz Ruecklastschrift";"Betrag";"Waehrung"',
            '"DE123";"01.03.25";"01.03.25";"Lastschrift";"REWE";"REWE Supermarkt";"";"";"";"";"";"";""-50,00";"EUR"',
            '"DE123";"15.03.25";"15.03.25";"Gutschrift";"Arbeitgeber GmbH";"Gehalt März";"";"";"";"";"";"";"2.500,00";"EUR"',
        ]);

        $tmpFile = tempnam(sys_get_temp_dir(), 'sparkasse_') . '.csv';
        file_put_contents($tmpFile, $csv);

        try {
            $result = $this->parser->parse($tmpFile);
            self::assertCount(2, $result);

            self::assertSame('2025-03-01', $result[0]['date']);
            self::assertSame(-50.0, $result[0]['amount']);
            self::assertSame('REWE Supermarkt', $result[0]['description']);
            self::assertSame('REWE', $result[0]['counterpartyName']);

            self::assertSame('2025-03-15', $result[1]['date']);
            self::assertSame(2500.0, $result[1]['amount']);
            self::assertSame('Gehalt März', $result[1]['description']);
        } finally {
            unlink($tmpFile);
        }
    }

    public function testSkipsRowsWithEmptyAmount(): void
    {
        $csv = implode("\n", [
            '"Auftragskonto";"Buchungstag";"Valutadatum";"Buchungstext";"Auftraggeber / Beguenstigter";"Verwendungszweck";"Glaeubiger ID";"Mandatsreferenz";"Kundenreferenz (End-to-End)";"Sammlerreferenz";"Lastschrift Ursprungsbetrag";"Auslagenersatz Ruecklastschrift";"Betrag";"Waehrung"',
            '"DE123";"01.03.25";"01.03.25";"Lastschrift";"REWE";"REWE Supermarkt";"";"";"";"";"";"";"";""',
        ]);

        $tmpFile = tempnam(sys_get_temp_dir(), 'sparkasse_') . '.csv';
        file_put_contents($tmpFile, $csv);

        try {
            $result = $this->parser->parse($tmpFile);
            self::assertCount(0, $result);
        } finally {
            unlink($tmpFile);
        }
    }

    public function testThrowsOnMissingFile(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->parser->parse('/tmp/nonexistent_file_xyz.csv');
    }

    public function testParsesGermanDecimalFormat(): void
    {
        $csv = implode("\n", [
            '"Auftragskonto";"Buchungstag";"Valutadatum";"Buchungstext";"Auftraggeber / Beguenstigter";"Verwendungszweck";"Glaeubiger ID";"Mandatsreferenz";"Kundenreferenz (End-to-End)";"Sammlerreferenz";"Lastschrift Ursprungsbetrag";"Auslagenersatz Ruecklastschrift";"Betrag";"Waehrung"',
            '"DE123";"10.06.25";"10.06.25";"Lastschrift";"Lidl";"Einkauf";"";"";"";"";"";"";""-1.234,56";"EUR"',
        ]);

        $tmpFile = tempnam(sys_get_temp_dir(), 'sparkasse_') . '.csv';
        file_put_contents($tmpFile, $csv);

        try {
            $result = $this->parser->parse($tmpFile);
            self::assertCount(1, $result);
            self::assertSame(-1234.56, $result[0]['amount']);
        } finally {
            unlink($tmpFile);
        }
    }
}
