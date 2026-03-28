<?php

declare(strict_types=1);

namespace App\Domain\Import;

class FormatDetector
{
    public function detect(string $filePath, string $originalFilename): string
    {
        $extension = strtolower(pathinfo($originalFilename, PATHINFO_EXTENSION));
        $firstLine = $this->readFirstLine($filePath);

        // MT940 always starts with :20:
        if (str_starts_with(trim($firstLine), ':20:')) {
            return 'mt940';
        }

        // STA and mt940 file extensions also indicate MT940
        if (in_array($extension, ['sta', 'mt940'])) {
            return 'mt940';
        }

        if ($extension === 'csv') {
            // Sparkasse CSV has a characteristic header line
            if (str_contains($firstLine, 'Auftragskonto') || str_contains($firstLine, 'Buchungstag')) {
                return 'csv_sparkasse';
            }

            return 'csv_generic';
        }

        throw new UnsupportedFormatException("Unknown file format: {$originalFilename}");
    }

    private function readFirstLine(string $path): string
    {
        $handle = fopen($path, 'r');
        if ($handle === false) {
            throw new \RuntimeException("Cannot open file: {$path}");
        }

        $line = fgets($handle);
        fclose($handle);

        if ($line === false || $line === '') {
            return '';
        }

        // Strip BOM (UTF-8, UTF-16 LE) and normalize encoding for comparison
        $line = ltrim($line, "\xEF\xBB\xBF\xFF\xFE");
        if (!mb_check_encoding($line, 'UTF-8')) {
            $line = mb_convert_encoding($line, 'UTF-8', 'Windows-1252');
        }

        return $line;
    }
}
