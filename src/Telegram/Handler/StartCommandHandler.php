<?php

declare(strict_types=1);

namespace App\Telegram\Handler;

use App\Repository\UserRepository;
use App\Telegram\CommandHandlerInterface;
use App\Telegram\Conversation\RegisterConversation;
use App\Telegram\ConversationManager;
use App\Telegram\Payload;
use App\Telegram\TelegramSender;
use App\Telegram\BotTranslator;

class StartCommandHandler implements CommandHandlerInterface
{
    public function __construct(
        private readonly UserRepository $userRepo,
        private readonly TelegramSender $sender,
        private readonly ConversationManager $conversationManager,
        private readonly BotTranslator $translator,
    ) {}

    /** @param array<string, mixed> $update */
    public function handle(int $chatId, array $update): void
    {
        $telegramUser = Payload::array($update, 'message', 'from');
        $user         = $this->userRepo->findByTelegramChatId($chatId);

        if ($user !== null) {
            $this->sender->sendMessage($chatId, $this->translator->trans('start.welcome_back', ['%name%' => $user->getName()]));
            $this->sendMainMenu($chatId);

            return;
        }

        // New user — begin registration conversation
        $this->conversationManager->start($chatId, RegisterConversation::class, [
            'telegramUser' => $telegramUser,
        ]);

        // Immediately trigger the first step by re-dispatching into the conversation
        $this->conversationManager->continue($chatId, $update);
    }

    private function sendMainMenu(int $chatId): void
    {
        $keyboard = [
            'keyboard' => [
                [['text' => '/balance'], ['text' => '/report']],
                [['text' => '/add'],     ['text' => '/income']],
                [['text' => '/help']],
            ],
            'resize_keyboard'   => true,
            'one_time_keyboard' => false,
        ];

        $this->sender->sendMessage(
            $chatId,
            $this->translator->trans('start.choose_action'),
            $keyboard,
        );
    }
}
