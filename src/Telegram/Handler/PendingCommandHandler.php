<?php

declare(strict_types=1);

namespace App\Telegram\Handler;

use App\Repository\ImportTransactionRepository;
use App\Repository\UserRepository;
use App\Telegram\CommandHandlerInterface;
use App\Telegram\TelegramSender;

class PendingCommandHandler implements CommandHandlerInterface
{
    public function __construct(
        private readonly UserRepository $userRepo,
        private readonly ImportTransactionRepository $importTxRepo,
        private readonly PendingImportHandler $pendingImportHandler,
        private readonly TelegramSender $sender,
    ) {}

    /** @param array<string, mixed> $update */
    public function handle(int $chatId, array $update): void
    {
        $user = $this->userRepo->findByTelegramChatId($chatId);
        if ($user === null || $user->getFamily() === null) {
            $this->sender->sendMessage($chatId, 'Please register first: /start');

            return;
        }

        $pending = $this->importTxRepo->findFirstPendingForFamily($user->getFamily());

        if ($pending === null) {
            $this->sender->sendMessage($chatId, 'No pending imports.');

            return;
        }

        $this->pendingImportHandler->sendNextPending($chatId, $pending->getImportFile());
    }
}
