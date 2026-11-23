<?php

declare(strict_types=1);

namespace App\Telegram;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class TelegramSender
{
    private const API_BASE = 'https://api.telegram.org/bot';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly string $botToken,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param array<string, mixed>|null $replyMarkup
     */
    public function sendMessage(
        int $chatId,
        string $text,
        ?array $replyMarkup = null,
        string $parseMode = '',
    ): void {
        $params = [
            'chat_id' => $chatId,
            'text' => $text,
        ];

        if ('' !== $parseMode) {
            $params['parse_mode'] = $parseMode;
        }

        if (null !== $replyMarkup) {
            // Pass as array — Symfony HttpClient serialises it via ['json' => ...] automatically
            $params['reply_markup'] = $replyMarkup;
        }

        if ($this->isOffline()) {
            $this->logOffline('sendMessage', $params);

            return;
        }

        try {
            $this->httpClient->request(
                'POST',
                self::API_BASE.$this->botToken.'/sendMessage',
                ['json' => $params],
            )->getContent(); // trigger the actual HTTP call
        } catch (\Throwable $e) {
            $this->logger->error('Telegram sendMessage failed', [
                'chatId' => $chatId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function sendPhoto(int $chatId, string $photoPath, string $caption = ''): void
    {
        if ($this->isOffline()) {
            $this->logOffline('sendPhoto', ['chat_id' => $chatId, 'photo' => $photoPath, 'caption' => $caption]);

            return;
        }

        try {
            $resource = fopen($photoPath, 'r');
            if (false === $resource) {
                throw new \RuntimeException("Cannot open file: $photoPath");
            }

            $this->httpClient->request(
                'POST',
                self::API_BASE.$this->botToken.'/sendPhoto',
                [
                    'body' => [
                        'chat_id' => (string) $chatId,
                        'caption' => $caption,
                        'photo' => $resource,
                    ],
                ],
            )->getContent();

            fclose($resource);
        } catch (\Throwable $e) {
            if (isset($resource) && is_resource($resource)) {
                fclose($resource);
            }
            $this->logger->error('Telegram sendPhoto failed', [
                'chatId' => $chatId,
                'path' => $photoPath,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function answerCallbackQuery(string $callbackQueryId, string $text = ''): void
    {
        if ($this->isOffline()) {
            return;
        }

        try {
            $this->httpClient->request(
                'POST',
                self::API_BASE.$this->botToken.'/answerCallbackQuery',
                ['json' => ['callback_query_id' => $callbackQueryId, 'text' => $text]],
            )->getContent();
        } catch (\Throwable $e) {
            $this->logger->error('Telegram answerCallbackQuery failed', [
                'callbackQueryId' => $callbackQueryId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @param array<string, mixed>|null $replyMarkup
     */
    public function editMessageText(
        int $chatId,
        int $messageId,
        string $text,
        ?array $replyMarkup = null,
        string $parseMode = '',
    ): void {
        $params = [
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'text' => $text,
        ];

        if ('' !== $parseMode) {
            $params['parse_mode'] = $parseMode;
        }

        if (null !== $replyMarkup) {
            $params['reply_markup'] = $replyMarkup;
        }

        if ($this->isOffline()) {
            $this->logOffline('editMessageText', $params);

            return;
        }

        try {
            $this->httpClient->request(
                'POST',
                self::API_BASE.$this->botToken.'/editMessageText',
                ['json' => $params],
            )->getContent();
        } catch (\Throwable $e) {
            $this->logger->error('Telegram editMessageText failed', [
                'chatId' => $chatId,
                'messageId' => $messageId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Without a bot token (local development, demos) replies are logged instead of sent.
     */
    private function isOffline(): bool
    {
        return '' === $this->botToken;
    }

    /** @param array<string, mixed> $params */
    private function logOffline(string $method, array $params): void
    {
        $this->logger->info('Telegram reply (offline) {method}: {text}', [
            'method' => $method,
            'text' => $params['text'] ?? $params['caption'] ?? '',
            'reply_markup' => $params['reply_markup'] ?? null,
        ]);
    }
}
