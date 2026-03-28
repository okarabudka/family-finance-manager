<?php

declare(strict_types=1);

namespace App\Telegram;

class CommandRegistry
{
    /**
     * @param array<string, CommandHandlerInterface> $handlers
     */
    public function __construct(
        private readonly array $handlers,
    ) {}

    /**
     * @param array<string, mixed> $update
     */
    public function execute(string $command, array $update): void
    {
        $chatId  = $update['message']['chat']['id'] ?? 0;
        $handler = $this->handlers[$command] ?? null;

        if ($handler === null) {
            // Unknown command — silently ignore
            return;
        }

        $handler->handle($chatId, $update);
    }
}
