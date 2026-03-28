<?php

declare(strict_types=1);

namespace App\Domain\Import;

interface ImportParserInterface
{
    /**
     * Parses a bank statement file and returns an array of raw transactions.
     * Each element: ['date', 'amount', 'description', 'counterpartyName', 'counterpartyIban']
     *
     * @return array<int, array{date: string, amount: float, description: string, counterpartyName: ?string, counterpartyIban: ?string}>
     */
    public function parse(string $filePath): array;

    public function supports(string $format): bool;
}
