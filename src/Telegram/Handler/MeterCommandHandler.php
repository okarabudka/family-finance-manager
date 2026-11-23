<?php

declare(strict_types=1);

namespace App\Telegram\Handler;

use App\Repository\UserRepository;
use App\Telegram\BotTranslator;
use App\Telegram\CommandHandlerInterface;
use App\Telegram\Conversation\AddMeterConversation;
use App\Telegram\ConversationManager;
use App\Telegram\TelegramSender;

class MeterCommandHandler implements CommandHandlerInterface
{
    public function __construct(
        private readonly TelegramSender $sender,
        private readonly ConversationManager $conversationManager,
        private readonly UserRepository $userRepo,
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

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '⚡ '.$this->translator->trans('meter.type.electricity'), 'callback_data' => 'meter:type:electricity'],
                    ['text' => '💧 '.$this->translator->trans('meter.type.water'), 'callback_data' => 'meter:type:water'],
                ],
            ],
        ];

        $this->sender->sendMessage(
            $chatId,
            $this->translator->trans('meter.choose_type'),
            $keyboard,
            'Markdown',
        );

        $this->conversationManager->start($chatId, AddMeterConversation::class);
    }
}
