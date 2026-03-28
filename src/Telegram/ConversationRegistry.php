<?php

declare(strict_types=1);

namespace App\Telegram;

class ConversationRegistry
{
    /**
     * @param array<string, ConversationInterface> $conversations
     */
    public function __construct(
        private readonly array $conversations,
    ) {}

    public function get(string $class): ConversationInterface
    {
        return $this->conversations[$class]
            ?? throw new \RuntimeException("Conversation not registered: $class");
    }
}
