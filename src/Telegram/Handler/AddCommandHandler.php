<?php

declare(strict_types=1);

namespace App\Telegram\Handler;

use App\Telegram\CommandHandlerInterface;
use App\Telegram\Conversation\AddExpenseConversation;
use App\Telegram\ConversationManager;

class AddCommandHandler implements CommandHandlerInterface
{
    public function __construct(
        private readonly ConversationManager $conversationManager,
    ) {}

    /** @param array<string, mixed> $update */
    public function handle(int $chatId, array $update): void
    {
        $this->conversationManager->start($chatId, AddExpenseConversation::class);
        // Immediately trigger the first step (askAmount)
        $this->conversationManager->continue($chatId, $update);
    }
}
