<?php

declare(strict_types=1);

namespace App\Telegram;

interface ConversationInterface
{
    /**
     * @param array<string, mixed> $update
     * @param array<string, mixed> $state
     */
    public function handle(int $chatId, array $update, array $state): void;
}
