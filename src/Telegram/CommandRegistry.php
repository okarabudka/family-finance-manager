<?php

declare(strict_types=1);

namespace App\Telegram;

use Psr\Log\LoggerInterface;

class CommandRegistry
{
    /**
     * @param array<string, CommandHandlerInterface> $handlers
     */
    public function __construct(
        private readonly array $handlers,
        private readonly TelegramSender $sender,
        private readonly LoggerInterface $logger,
        private readonly BotTranslator $translator,
    ) {}

    /**
     * @param array<string, mixed> $update
     */
    public function execute(string $command, array $update): void
    {
        $chatId  = (int) ($update['message']['chat']['id'] ?? 0);
        $handler = $this->handlers[$command] ?? null;

        if ($handler === null) {
            $this->logger->info('Unknown Telegram command', ['command' => $command, 'chatId' => $chatId]);
            $this->sender->sendMessage($chatId, $this->translator->trans('dispatcher.unknown_command'));

            return;
        }

        $handler->handle($chatId, $update);
    }
}
