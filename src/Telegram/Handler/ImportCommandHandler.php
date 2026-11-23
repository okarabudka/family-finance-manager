<?php

declare(strict_types=1);

namespace App\Telegram\Handler;

use App\Entity\Account;
use App\Repository\AccountRepository;
use App\Repository\UserRepository;
use App\Telegram\BotTranslator;
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

        $accounts = $this->accountRepo->findActiveByFamily($user->getFamily());

        if (empty($accounts)) {
            $this->sender->sendMessage($chatId, $this->translator->trans('import.no_accounts'));

            return;
        }

        // Build inline keyboard — one button per account
        $keyboard = [
            'inline_keyboard' => array_map(
                static fn (Account $a) => [
                    ['text' => $a->getName(), 'callback_data' => 'import:account:'.$a->getId()],
                ],
                $accounts
            ),
        ];

        $this->sender->sendMessage(
            $chatId,
            $this->translator->trans('import.choose_account'),
            $keyboard,
            'Markdown',
        );

        $this->conversationManager->start($chatId, ImportConversation::class);
    }
}
