<?php

declare(strict_types=1);

namespace App\Message;

/**
 * Dispatched to trigger generation of a monthly analytics report for a family.
 * Handled asynchronously by GenerateReportMessageHandler.
 */
final readonly class GenerateReportMessage
{
    public function __construct(
        /** Family ID (UUID string) */
        public string $familyId,

        /** Four-digit year, e.g. 2025 */
        public int $year,

        /** Month number 1–12 */
        public int $month,
    ) {}
}
