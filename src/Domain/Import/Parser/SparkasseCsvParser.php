<?php

declare(strict_types=1);

namespace App\Domain\Import\Parser;

use App\Domain\Import\ImportParserInterface;
use League\Csv\Reader;

/**
 * Parser for Sparkasse CSV export format.
 *
 * Sparkasse CSV header (semicolon-delimited, quoted):
 *   "Auftragskonto";"Buchungstag";"Valutadatum";"Buchungstext";
 *   "Auftraggeber / Beguenstigter";"Verwendungszweck";"Glaeubiger ID";
 *   "Mandatsreferenz";"Kundenreferenz (End-to-End)";"Sammlerreferenz";
 *   "Lastschrift Ursprungsbetrag";"Auslagenersatz Ruecklastschrift";
 *   "Betrag";"Waehrung"
 *
 * Encoding: Windows-1252 or UTF-8 with BOM.
 * Decimal separator: comma (German locale).
 * Date format: DD.MM.YY or DD.MM.YYYY.
 */
class SparkasseCsvParser implements ImportParserInterface
{
    public function supports(string $format): bool
    {
        return $format === 'csv_sparkasse';
    }

    public function parse(string $filePath): array
    {
        $content = @file_get_contents($filePath);
        if ($content === false) {
            throw new \RuntimeException("Cannot read file: {$filePath}");
        }

        // Sparkasse exports in Windows-1252 or UTF-8 with BOM
        if (!mb_check_encoding($content, 'UTF-8')) {
            $content = mb_convert_encoding($content, 'UTF-8', 'Windows-1252');
        }

        // Strip UTF-8 BOM if present
        $content = ltrim($content, "\xEF\xBB\xBF");

        $csv = Reader::fromString($content);
        $csv->setDelimiter(';');
        $csv->setEnclosure('"');

        // Sparkasse places the header on the first row
        $csv->setHeaderOffset(0);

        $transactions = [];

        foreach ($csv->getRecords() as $record) {
            $amountStr = $record['Betrag'] ?? '';
            if ($amountStr === '') {
                continue;
            }

            // German number format: 1.234,56 → 1234.56
            $amount = (float) str_replace(
                ['.', ','],
                ['', '.'],
                $amountStr
            );

            // Parse date: DD.MM.YY or DD.MM.YYYY
            $dateStr = $record['Buchungstag'] ?? $record['Valutadatum'] ?? '';
            $date = \DateTime::createFromFormat('d.m.y', $dateStr)
                ?: \DateTime::createFromFormat('d.m.Y', $dateStr);

            if ($date === false || $dateStr === '') {
                continue;
            }

            $transactions[] = [
                'date'             => $date->format('Y-m-d'),
                'amount'           => $amount,
                'description'      => trim($record['Verwendungszweck'] ?? ''),
                'counterpartyName' => trim($record['Auftraggeber / Beguenstigter'] ?? '') ?: null,
                'counterpartyIban' => null, // Sparkasse CSV does not always include IBAN
            ];
        }

        return $transactions;
    }
}
