<?php

declare(strict_types=1);

namespace App\Telegram;

use Psr\Log\LoggerInterface;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class TelegramSender
{
    private const API_BASE = 'https://api.telegram.org/bot';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly string $botToken,
        private readonly LoggerInterface $logger,
    ) {}

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
            'text'    => $text,
        ];

        if ($parseMode !== '') {
            $params['parse_mode'] = $parseMode;
        }

        if ($replyMarkup !== null) {
            // Pass as array — Symfony HttpClient serialises it via ['json' => ...] automatically
            $params['reply_markup'] = $replyMarkup;
        }

        try {
            $this->httpClient->request(
                'POST',
                self::API_BASE . $this->botToken . '/sendMessage',
                ['json' => $params],
            )->getContent(); // trigger the actual HTTP call
        } catch (\Throwable $e) {
            $this->logger->error('Telegram sendMessage failed', [
                'chatId' => $chatId,
                'error'  => $e->getMessage(),
            ]);
        }
    }

    public function sendPhoto(int $chatId, string $photoPath, string $caption = ''): void
    {
        try {
            $resource = fopen($photoPath, 'r');
            if ($resource === false) {
                throw new \RuntimeException("Cannot open file: $photoPath");
            }

            $this->httpClient->request(
                'POST',
                self::API_BASE . $this->botToken . '/sendPhoto',
                [
                    'body' => [
                        'chat_id' => (string) $chatId,
                        'caption' => $caption,
                        'photo'   => $resource,
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
                'path'   => $photoPath,
                'error'  => $e->getMessage(),
            ]);
        }
    }

    public function answerCallbackQuery(string $callbackQueryId, string $text = ''): void
    {
        try {
            $this->httpClient->request(
                'POST',
                self::API_BASE . $this->botToken . '/answerCallbackQuery',
                ['json' => ['callback_query_id' => $callbackQueryId, 'text' => $text]],
            )->getContent();
        } catch (\Throwable $e) {
            $this->logger->error('Telegram answerCallbackQuery failed', [
                'callbackQueryId' => $callbackQueryId,
                'error'           => $e->getMessage(),
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
            'chat_id'    => $chatId,
            'message_id' => $messageId,
            'text'       => $text,
        ];

        if ($parseMode !== '') {
            $params['parse_mode'] = $parseMode;
        }

        if ($replyMarkup !== null) {
            $params['reply_markup'] = $replyMarkup;
        }

        try {
            $this->httpClient->request(
                'POST',
                self::API_BASE . $this->botToken . '/editMessageText',
                ['json' => $params],
            )->getContent();
        } catch (\Throwable $e) {
            $this->logger->error('Telegram editMessageText failed', [
                'chatId'    => $chatId,
                'messageId' => $messageId,
                'error'     => $e->getMessage(),
            ]);
        }
    }
}
