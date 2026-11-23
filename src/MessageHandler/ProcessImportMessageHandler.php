<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Domain\Import\ImportProcessor;
use App\Message\ProcessImportMessage;
use App\Repository\ImportFileRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
class ProcessImportMessageHandler
{
    public function __construct(
        private readonly ImportFileRepository $importFileRepo,
        private readonly ImportProcessor $processor,
    ) {
    }

    public function __invoke(ProcessImportMessage $message): void
    {
        $importFile = $this->importFileRepo->find($message->importFileId);

        if (null === $importFile) {
            // ImportFile may have been deleted before the message was processed
            return;
        }

        $this->processor->process($importFile);
    }
}
