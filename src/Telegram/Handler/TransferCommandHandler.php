<?php

declare(strict_types=1);

namespace App\Telegram\Handler;

use App\Telegram\CommandHandlerInterface;
use App\Telegram\Conversation\AddTransferConversation;
use App\Telegram\ConversationManager;

class TransferCommandHandler implements CommandHandlerInterface
{
    public function __construct(
        private readonly ConversationManager $conversationManager,
    ) {}

    /** @param array<string, mixed> $update */
    public function handle(int $chatId, array $update): void
    {
        $this->conversationManager->start($chatId, AddTransferConversation::class);
        // Immediately trigger the first step (askAmount)
        $this->conversationManager->continue($chatId, $update);
    }
}
