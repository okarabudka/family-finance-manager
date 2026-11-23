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
    ) {
    }

    public function __invoke(SendNotificationMessage $message): void
    {
        if ('telegram' !== $message->channel) {
            return;
        }

        $user = $this->userRepo->find($message->userId);

        if (null === $user) {
            return;
        }

        $chatId = $user->getTelegramChatId();
        if (null === $chatId) {
            return;
        }

        $this->sender->sendMessage($chatId, $message->message);
    }
}
