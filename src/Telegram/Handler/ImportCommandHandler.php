<?php

declare(strict_types=1);

namespace App\Telegram\Handler;

use App\Entity\Account;
use App\Repository\AccountRepository;
use App\Repository\UserRepository;
use App\Telegram\CommandHandlerInterface;
use App\Telegram\Conversation\ImportConversation;
use App\Telegram\ConversationManager;
use App\Telegram\TelegramSender;

class ImportCommandHandler implements CommandHandlerInterface
{
    public function __construct(
        private readonly TelegramSender $sender,
        private readonly ConversationManager $conversationManager,
        private readonly UserRepository $userRepo,
        private readonly AccountRepository $accountRepo,
    ) {}

    /** @param array<string, mixed> $update */
    public function handle(int $chatId, array $update): void
    {
        $user = $this->userRepo->findByTelegramChatId($chatId);

        if ($user === null || $user->getFamily() === null) {
            $this->sender->sendMessage($chatId, 'Please register first: /start');
            return;
        }

        $accounts = $this->accountRepo->findActiveByFamily($user->getFamily());

        if (empty($accounts)) {
            $this->sender->sendMessage($chatId, 'No active accounts found. Please create an account first.');
            return;
        }

        // Build inline keyboard — one button per account
        $keyboard = [
            'inline_keyboard' => array_map(
                static fn(Account $a) => [
                    ['text' => $a->getName(), 'callback_data' => 'import:account:' . $a->getId()],
                ],
                $accounts
            ),
        ];

        $this->sender->sendMessage(
            $chatId,
            "*Import bank statement*\n\nSelect the account this statement belongs to:",
            $keyboard,
            'Markdown',
        );

        $this->conversationManager->start($chatId, ImportConversation::class);
    }
}
