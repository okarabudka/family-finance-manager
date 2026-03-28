<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\SendNotificationMessage;
use App\Repository\UserRepository;
use App\Telegram\TelegramSender;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
class SendNotificationMessageHandler
{
    public function __construct(
        private readonly UserRepository $userRepo,
        private readonly TelegramSender $sender,
    ) {}

    public function __invoke(SendNotificationMessage $message): void
    {
        if ($message->channel !== 'telegram') {
            return;
        }

        $user = $this->userRepo->find($message->userId);

        if ($user === null) {
            return;
        }

        $chatId = $user->getTelegramChatId();
        if ($chatId === null) {
            return;
        }

        $this->sender->sendMessage($chatId, $message->message);
    }
}
