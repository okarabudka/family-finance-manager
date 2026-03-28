<?php

declare(strict_types=1);

namespace App\Telegram\Handler;

use App\Domain\Account\AccountService;
use App\Entity\AccountType;
use App\Repository\AccountRepository;
use App\Repository\UserRepository;
use App\Telegram\CommandHandlerInterface;
use App\Telegram\TelegramSender;

class BalanceCommandHandler implements CommandHandlerInterface
{
    public function __construct(
        private readonly UserRepository $userRepo,
        private readonly AccountRepository $accountRepo,
        private readonly AccountService $accountService,
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

        $family   = $user->getFamily();
        $accounts = $this->accountRepo->findActiveByFamily($family);

        $text = "*Current Balance*\n\n";

        foreach ($accounts as $account) {
            $balance = $this->accountService->getBalance($account);
            $icon    = match ($account->getType()) {
                AccountType::Checking   => 'Bank',
                AccountType::Savings    => 'Savings',
                AccountType::Cash       => 'Cash',
                AccountType::CreditCard => 'Card',
            };
            $balanceFormatted = number_format($balance, 2, '.', ' ');
            $text .= "{$icon} *{$account->getName()}*: {$balanceFormatted} {$account->getCurrency()}\n";
        }

        $totals = $this->accountService->getTotalBalance($family);
        $text .= "\n*Total*: ";

        foreach ($totals as $currency => $total) {
            $totalFormatted = number_format((float) $total, 2, '.', ' ');
            $text .= "{$totalFormatted} {$currency} ";
        }

        $this->sender->sendMessage($chatId, rtrim($text), parseMode: 'Markdown');
    }
}
