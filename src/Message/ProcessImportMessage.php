<?php

declare(strict_types=1);

namespace App\Message;

/**
 * Dispatched when a bank statement file is uploaded and ready to be processed.
 * Handled asynchronously by ProcessImportMessageHandler.
 */
final readonly class ProcessImportMessage
{
    public function __construct(
        public string $importFileId,
    ) {
    }
}
