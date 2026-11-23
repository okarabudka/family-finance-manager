<?php

declare(strict_types=1);

namespace App\Telegram;

use App\Entity\ImportFile;
use App\Entity\User;
use Psr\Log\LoggerInterface;

class TelegramNotifier
{
    public function __construct(
        private readonly TelegramSender $sender,
        private readonly LoggerInterface $logger,
        private readonly BotTranslator $translator,
    ) {
    }

    public function notifyImportComplete(ImportFile $importFile, int $pendingCount): void
    {
        $user = $importFile->getUploadedBy();
        $chatId = $user->getTelegramChatId();

        if (null === $chatId) {
            $this->logger->warning('TelegramNotifier: user has no Telegram chat ID', [
                'userId' => (string) $user->getId(),
            ]);

            return;
        }

        $imported = $importFile->getImportedRows();
        $skipped = $importFile->getSkippedRows();

        $text = $this->translator->trans('import.complete', [
            '%file%' => $importFile->getOriginalFilename(),
            '%imported%' => $imported,
            '%skipped%' => $skipped,
        ])."\n";

        if ($pendingCount > 0) {
            $text .= $this->translator->trans('import.complete_pending', ['%count%' => $pendingCount]);
        } else {
            $text .= "\n".$this->translator->trans('import.complete_all_categorised');
        }

        $this->sender->sendMessage($chatId, $text, parseMode: 'Markdown');
    }

    public function notifyImportFailed(ImportFile $importFile, string $reason): void
    {
        $user = $importFile->getUploadedBy();
        $chatId = $user->getTelegramChatId();

        if (null === $chatId) {
            return;
        }

        $this->sender->sendMessage(
            $chatId,
            $this->translator->trans('import.failed', ['%file%' => $importFile->getOriginalFilename(), '%reason%' => $reason]),
            parseMode: 'Markdown',
        );
    }

    public function notify(User $user, string $message, string $parseMode = ''): void
    {
        $chatId = $user->getTelegramChatId();
        if (null === $chatId) {
            return;
        }

        $this->sender->sendMessage($chatId, $message, parseMode: $parseMode);
    }
}
