<?php

declare(strict_types=1);

namespace App\Telegram\Handler;

use App\Repository\ImportTransactionRepository;
use App\Repository\UserRepository;
use App\Telegram\BotTranslator;
use App\Telegram\CommandHandlerInterface;
use App\Telegram\TelegramSender;

class PendingCommandHandler implements CommandHandlerInterface
{
    public function __construct(
        private readonly UserRepository $userRepo,
        private readonly ImportTransactionRepository $importTxRepo,
        private readonly PendingImportHandler $pendingImportHandler,
        private readonly TelegramSender $sender,
        private readonly BotTranslator $translator,
    ) {
    }

    /** @param array<string, mixed> $update */
    public function handle(int $chatId, array $update): void
    {
        $user = $this->userRepo->findByTelegramChatId($chatId);
        if (null === $user || null === $user->getFamily()) {
            $this->sender->sendMessage($chatId, $this->translator->trans('common.register_first'));

            return;
        }

        $pending = $this->importTxRepo->findFirstPendingForFamily($user->getFamily());

        if (null === $pending) {
            $this->sender->sendMessage($chatId, $this->translator->trans('pending.none'));

            return;
        }

        $this->pendingImportHandler->sendNextPending($chatId, $pending->getImportFile());
    }
}
