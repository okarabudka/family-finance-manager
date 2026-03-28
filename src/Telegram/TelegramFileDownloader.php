<?php

declare(strict_types=1);

namespace App\Telegram;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class TelegramFileDownloader
{
    private const API_BASE = 'https://api.telegram.org/bot';
    private const FILE_BASE = 'https://api.telegram.org/file/bot';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly string $botToken,
        private readonly string $uploadDir,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Downloads a file by its Telegram file_id and saves it to local storage.
     * Returns the local path on success, or null on failure.
     */
    public function download(string $fileId, string $subDir = 'telegram'): ?string
    {
        try {
            // Step 1: resolve the file path on Telegram servers
            $response = $this->httpClient->request(
                'GET',
                self::API_BASE . $this->botToken . '/getFile',
                ['query' => ['file_id' => $fileId]],
            );

            $data = $response->toArray();

            if (!($data['ok'] ?? false) || empty($data['result']['file_path'])) {
                $this->logger->error('Telegram getFile failed', ['file_id' => $fileId, 'data' => $data]);

                return null;
            }

            $telegramFilePath = $data['result']['file_path'];

            // Step 2: download the actual file bytes
            $fileResponse = $this->httpClient->request(
                'GET',
                self::FILE_BASE . $this->botToken . '/' . $telegramFilePath,
            );

            $localDir = rtrim($this->uploadDir, '/') . '/' . trim($subDir, '/');
            if (!is_dir($localDir) && !mkdir($localDir, 0755, true)) {
                throw new \RuntimeException("Cannot create directory: $localDir");
            }

            $extension  = pathinfo($telegramFilePath, PATHINFO_EXTENSION);
            $localName  = bin2hex(random_bytes(16)) . ($extension !== '' ? '.' . $extension : '');
            $localPath  = $localDir . '/' . $localName;

            file_put_contents($localPath, $fileResponse->getContent());

            return $localPath;
        } catch (\Throwable $e) {
            $this->logger->error('TelegramFileDownloader failed', [
                'file_id' => $fileId,
                'error'   => $e->getMessage(),
            ]);

            return null;
        }
    }
}
