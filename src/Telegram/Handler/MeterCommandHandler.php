<?php

declare(strict_types=1);

namespace App\Telegram\Handler;

use App\Repository\UserRepository;
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
    ) {}

    /** @param array<string, mixed> $update */
    public function handle(int $chatId, array $update): void
    {
        $user = $this->userRepo->findByTelegramChatId($chatId);

        if ($user === null || $user->getFamily() === null) {
            $this->sender->sendMessage($chatId, 'Please register first: /start');
            return;
        }

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '⚡ Electricity', 'callback_data' => 'meter:type:electricity'],
                    ['text' => '💧 Water',       'callback_data' => 'meter:type:water'],
                ],
            ],
        ];

        $this->sender->sendMessage(
            $chatId,
            "*Meter Reading*\n\nSelect meter type:",
            $keyboard,
            'Markdown',
        );

        $this->conversationManager->start($chatId, AddMeterConversation::class);
    }
}
