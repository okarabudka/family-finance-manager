<?php

declare(strict_types=1);

namespace App\Telegram;

interface CommandHandlerInterface
{
    /** @param array<string, mixed> $update */
    public function handle(int $chatId, array $update): void;
}
