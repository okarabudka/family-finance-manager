<?php

declare(strict_types=1);

namespace App\Domain\Transaction;

/**
 * Parses a free-text message into a QuickInputResult.
 *
 * Accepted formats (amount may use "," or "." as decimal separator):
 *   "50 groceries"
 *   "150,50 restaurant"
 *   "1 234.99 supermarket"
 *   "groceries 50"          — description first, then amount
 */
class QuickInputParser
{
    /**
     * Returns a QuickInputResult on success or null if the text cannot be parsed.
     */
    public function parse(string $text): ?QuickInputResult
    {
        $text = trim($text);

        if ($text === '') {
            return null;
        }

        // Pattern: optional leading/trailing whitespace around the amount token
        // Amount may be: 1234 / 1234.56 / 1234,56 / 1 234.56 (space-grouped thousands)
        $amountPattern = '(?:\d[\d\s]*(?:[.,]\d{1,2})?)';

        // Try: amount at the beginning, description follows
        if (preg_match('/^(' . $amountPattern . ')\s+(.+)$/u', $text, $m)) {
            $amount      = $this->normaliseAmount($m[1]);
            $description = trim($m[2]);

            if ($amount > 0.0 && $description !== '') {
                return new QuickInputResult(number_format($amount, 2, '.', ''), $description);
            }
        }

        // Try: description at the beginning, amount at the end
        if (preg_match('/^(.+?)\s+(' . $amountPattern . ')$/u', $text, $m)) {
            $description = trim($m[1]);
            $amount      = $this->normaliseAmount($m[2]);

            if ($amount > 0.0 && $description !== '') {
                return new QuickInputResult(number_format($amount, 2, '.', ''), $description);
            }
        }

        return null;
    }

    private function normaliseAmount(string $raw): float
    {
        // Remove spaces used as thousands separators
        $clean = str_replace(' ', '', $raw);
        // Normalise decimal separator to dot
        $clean = str_replace(',', '.', $clean);

        return (float) $clean;
    }
}
