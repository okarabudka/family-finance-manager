<?php

declare(strict_types=1);

namespace App\Domain\Import\Parser;

use App\Domain\Import\ImportParserInterface;

/**
 * Simple regex-based MT940 parser.
 *
 * MT940 key field tags used here:
 *   :20:  — transaction reference number (statement identifier)
 *   :61:  — statement line (value date, amount, reference)
 *   :86:  — information to account owner (description / structured remittance info)
 *
 * Codelicious/mt940 is not available for this PHP version, so we parse manually.
 */
class Mt940Parser implements ImportParserInterface
{
    public function supports(string $format): bool
    {
        return $format === 'mt940';
    }

    public function parse(string $filePath): array
    {
        $content = @file_get_contents($filePath);
        if ($content === false) {
            throw new \RuntimeException("Cannot read file: {$filePath}");
        }

        // Normalise line endings
        $content = str_replace(["\r\n", "\r"], "\n", $content);

        $transactions = [];

        // Split into transaction blocks. Each :61: starts a transaction entry.
        // We match pairs of :61: (statement line) followed by optional :86: (description).
        // Pattern explanation:
        //   :61:  — field tag
        //   (\d{6})  — value date YYMMDD
        //   (\d{6})?  — booking date YYMMDD (optional)
        //   ([CD])  — C=credit (income), D=debit (expense)
        //   R?  — reversal flag (optional)
        //   ([A-Z]{3})?  — currency (optional, old format)
        //   ([\d,]+)  — amount with comma as decimal separator
        //   [A-Z]{4}  — transaction code (NTRN, NTRF, etc.)
        //   (.+?)  — reference
        //   (?=\n:|$)  — lookahead for next tag or end
        preg_match_all(
            '/:61:\n?(\d{6})(\d{6})?([CD])R?([A-Z]{3})?([\d,]+)[A-Z]{4}(.+?)(?=\n:|\z)/s',
            $content,
            $txMatches,
            PREG_SET_ORDER | PREG_OFFSET_CAPTURE
        );

        // Also extract :86: description blocks indexed by their offset in the content
        preg_match_all('/:86:\n?(.+?)(?=\n:|\z)/s', $content, $descMatches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);

        foreach ($txMatches as $i => $match) {
            $valueDateStr = $match[1][0];   // YYMMDD
            $creditDebit  = $match[3][0];   // C or D
            $amountStr    = $match[5][0];   // e.g. 1234,56

            // Parse amount
            $amount = (float) str_replace(',', '.', $amountStr);
            if ($creditDebit === 'D') {
                $amount = -$amount;
            }

            // Parse date: YYMMDD → DateTimeImmutable
            $date = \DateTimeImmutable::createFromFormat('ymd', $valueDateStr);
            if ($date === false) {
                continue;
            }

            // Find the :86: block that comes right after this :61: block
            $description = '';
            $txOffset = $match[0][1];
            foreach ($descMatches as $descMatch) {
                $descOffset = $descMatch[0][1];
                if ($descOffset > $txOffset) {
                    $raw = $descMatch[1][0];
                    $description = $this->cleanDescription($raw);
                    break;
                }
            }

            // Extract counterparty IBAN from description (IBAN+ tag in structured MT940)
            $counterpartyIban = $this->extractIban($descMatches[$i][1][0] ?? '');
            $counterpartyName = $this->extractName($descMatches[$i][1][0] ?? '');

            $transactions[] = [
                'date'             => $date->format('Y-m-d'),
                'amount'           => $amount,
                'description'      => $description,
                'counterpartyName' => $counterpartyName !== '' ? $counterpartyName : null,
                'counterpartyIban' => $counterpartyIban !== '' ? $counterpartyIban : null,
            ];
        }

        return $transactions;
    }

    /**
     * Extract human-readable description from structured MT940 :86: field.
     * Structured fields use codes like SVWZ+, EREF+, KREF+, ABWA+, etc.
     */
    private function cleanDescription(string $raw): string
    {
        // Remove line breaks inserted for MT940 line-length limits
        $raw = preg_replace('/\n/', '', $raw) ?? $raw;

        // Extract SVWZ+ (Verwendungszweck / payment purpose) — most human-readable part
        if (preg_match('/SVWZ\+([^+?]+)/', $raw, $m)) {
            return trim($m[1]);
        }

        // Fallback: strip all structured field codes (4 uppercase letters followed by +)
        $cleaned = preg_replace('/[A-Z]{4}\+/', ' ', $raw) ?? $raw;

        return trim(preg_replace('/\s+/', ' ', $cleaned) ?? $cleaned);
    }

    private function extractIban(string $raw): string
    {
        // IBAN+ tag in structured remittance info
        if (preg_match('/IBAN\+([A-Z]{2}[0-9A-Z]{13,30})/', $raw, $m)) {
            return $m[1];
        }

        // Also try to find a standalone IBAN-like pattern
        if (preg_match('/\b([A-Z]{2}\d{2}[0-9A-Z]{11,27})\b/', $raw, $m)) {
            return $m[1];
        }

        return '';
    }

    private function extractName(string $raw): string
    {
        // ABWA+ (Abweichender Auftraggeber) or NAME+ tag
        if (preg_match('/(?:ABWA|NAME)\+([^+?]+)/', $raw, $m)) {
            return trim($m[1]);
        }

        return '';
    }
}
