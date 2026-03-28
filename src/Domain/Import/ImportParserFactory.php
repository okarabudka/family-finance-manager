<?php

declare(strict_types=1);

namespace App\Domain\Import;

class ImportParserFactory
{
    /**
     * @param iterable<ImportParserInterface> $parsers
     */
    public function __construct(
        private readonly iterable $parsers,
    ) {}

    public function getParser(string $format): ImportParserInterface
    {
        foreach ($this->parsers as $parser) {
            if ($parser->supports($format)) {
                return $parser;
            }
        }

        throw new UnsupportedFormatException("No parser available for format: {$format}");
    }
}
