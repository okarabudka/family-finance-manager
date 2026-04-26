<?php

declare(strict_types=1);

namespace App\Telegram\Handler;

use App\Repository\UserRepository;
use App\Telegram\CommandHandlerInterface;
use App\Telegram\TelegramSender;

class HelpCommandHandler implements CommandHandlerInterface
{
    public function __construct(
        private readonly UserRepository $userRepo,
        private readonly TelegramSender $sender,
    ) {}

    /** @param array<string, mixed> $update */
    public function handle(int $chatId, array $update): void
    {
        $user = $this->userRepo->findByTelegramChatId($chatId);
        if ($user === null || $user->getFamily() === null) {
            $this->sender->sendMessage($chatId, 'Please register first: /start');

            return;
        }

        $text = "*Available commands:*\n\n"
            . "/start — Register / welcome\n"
            . "/balance — Show account balances\n"
            . "/add — Add expense (or use quick input: \"50 groceries\")\n"
            . "/income — Add income\n"
            . "/transfer — Transfer between accounts\n"
            . "/report — Monthly report\n"
            . "/stats — Spending chart\n"
            . "/import — Import bank statement\n"
            . "/pending — Review pending imports\n"
            . "/meter — Submit meter reading (electricity/water)\n"
            . "/help — Show this help";

        $this->sender->sendMessage($chatId, $text, parseMode: 'Markdown');
    }
}
