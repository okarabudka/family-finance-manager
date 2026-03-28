<?php

declare(strict_types=1);

namespace App\Telegram;

use App\Telegram\Handler\MessageHandler;
use App\Telegram\Handler\PendingImportHandler;
use App\Telegram\Handler\ReportCommandHandler;
use App\Telegram\Handler\TransactionCallbackHandler;
use Psr\Log\LoggerInterface;

class UpdateDispatcher
{
    public function __construct(
        private readonly CommandRegistry $commandRegistry,
        private readonly ConversationManager $conversationManager,
        private readonly MessageHandler $messageHandler,
        private readonly TelegramSender $sender,
        private readonly LoggerInterface $logger,
        private readonly PendingImportHandler $pendingImportHandler,
        private readonly TransactionCallbackHandler $transactionCallbackHandler,
        private readonly ReportCommandHandler $reportCommandHandler,
    ) {}

    /** @param array<string, mixed> $update */
    public function dispatch(array $update): void
    {
        try {
            $this->doDispatch($update);
        } catch (\Throwable $e) {
            $this->logger->error('UpdateDispatcher exception', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }

    /** @param array<string, mixed> $update */
    private function doDispatch(array $update): void
    {
        $chatId = $this->extractChatId($update);

        if ($chatId === 0) {
            return;
        }

        // Callback queries (inline keyboard button presses)
        if (isset($update['callback_query'])) {
            $callbackQuery = $update['callback_query'];
            $callbackData  = $callbackQuery['data'] ?? '';

            if ($this->conversationManager->hasActiveConversation($chatId)) {
                // Wrap callback_query into a pseudo-update so conversations can read it uniformly
                $this->conversationManager->continue($chatId, $update);
            } elseif (str_starts_with($callbackData, 'pending:')) {
                $this->pendingImportHandler->handleCallback($chatId, $callbackData);
            } elseif (str_starts_with($callbackData, 'tx:')) {
                $this->transactionCallbackHandler->handleCallback($chatId, $callbackData);
            } elseif (str_starts_with($callbackData, 'report:')) {
                $this->reportCommandHandler->handleCallback($chatId, $callbackData);
            }

            // Always acknowledge the callback so Telegram stops the loading indicator
            $this->sender->answerCallbackQuery($callbackQuery['id']);

            return;
        }

        $text = $update['message']['text'] ?? '';

        // If a conversation is active, feed the message into it
        if ($this->conversationManager->hasActiveConversation($chatId)) {
            // /cancel ends any active conversation even from within it
            if ($this->isCommand($text, 'cancel')) {
                $this->conversationManager->end($chatId);
                $this->sender->sendMessage($chatId, 'Action cancelled.');

                return;
            }

            $this->conversationManager->continue($chatId, $update);

            return;
        }

        // Route slash commands
        if (str_starts_with($text, '/')) {
            $command = $this->extractCommand($text);

            if ($command === 'cancel') {
                // No active conversation — nothing to cancel
                $this->sender->sendMessage($chatId, 'Nothing to cancel.');

                return;
            }

            $this->commandRegistry->execute($command, $update);

            return;
        }

        // Free-text: quick expense entry
        $this->messageHandler->handle($update);
    }

    /** @param array<string, mixed> $update */
    private function extractChatId(array $update): int
    {
        return (int) (
            $update['message']['chat']['id']
            ?? $update['callback_query']['message']['chat']['id']
            ?? 0
        );
    }

    private function extractCommand(string $text): string
    {
        // Strip leading slash, remove @BotName suffix, take first word
        $withoutSlash = ltrim($text, '/');
        $firstWord    = explode(' ', $withoutSlash)[0];

        return explode('@', $firstWord)[0];
    }

    private function isCommand(string $text, string $command): bool
    {
        if (!str_starts_with($text, '/')) {
            return false;
        }

        return $this->extractCommand($text) === $command;
    }
}
