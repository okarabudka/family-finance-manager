<?php

declare(strict_types=1);

namespace App\Telegram\Handler;

use App\Repository\UserRepository;
use App\Telegram\BotTranslator;
use App\Telegram\CommandHandlerInterface;
use App\Telegram\TelegramSender;

class HelpCommandHandler implements CommandHandlerInterface
{
    public function __construct(
        private readonly UserRepository $userRepo,
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

        $text = $this->translator->trans('help.text');

        $this->sender->sendMessage($chatId, $text, parseMode: 'Markdown');
    }
}
