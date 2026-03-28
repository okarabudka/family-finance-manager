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
    ) {}

    public function notifyImportComplete(ImportFile $importFile, int $pendingCount): void
    {
        $user = $importFile->getUploadedBy();
        $chatId = $user->getTelegramChatId();

        if ($chatId === null) {
            $this->logger->warning('TelegramNotifier: user has no Telegram chat ID', [
                'userId' => (string) $user->getId(),
            ]);
            return;
        }

        $imported = $importFile->getImportedRows();
        $skipped  = $importFile->getSkippedRows();

        $text = "Import complete: *{$importFile->getOriginalFilename()}*\n\n"
            . "Imported automatically: *{$imported}*\n"
            . "Duplicates skipped: *{$skipped}*\n";

        if ($pendingCount > 0) {
            $text .= "Pending review: *{$pendingCount}*\n\n"
                . "Use /pending to review unrecognised transactions one by one.";
        } else {
            $text .= "\nAll transactions were categorised automatically.";
        }

        $this->sender->sendMessage($chatId, $text, parseMode: 'Markdown');
    }

    public function notifyImportFailed(ImportFile $importFile, string $reason): void
    {
        $user   = $importFile->getUploadedBy();
        $chatId = $user->getTelegramChatId();

        if ($chatId === null) {
            return;
        }

        $this->sender->sendMessage(
            $chatId,
            "Import failed: *{$importFile->getOriginalFilename()}*\n\nReason: {$reason}",
            parseMode: 'Markdown',
        );
    }

    public function notify(User $user, string $message, string $parseMode = ''): void
    {
        $chatId = $user->getTelegramChatId();
        if ($chatId === null) {
            return;
        }

        $this->sender->sendMessage($chatId, $message, parseMode: $parseMode);
    }
}
