<?php

declare(strict_types=1);

namespace App\Telegram\Handler;

use App\Domain\Account\AccountService;
use App\Repository\AccountRepository;
use App\Repository\UserRepository;
use App\Telegram\BotTranslator;
use App\Telegram\CommandHandlerInterface;
use App\Telegram\TelegramSender;

class BalanceCommandHandler implements CommandHandlerInterface
{
    public function __construct(
        private readonly UserRepository $userRepo,
        private readonly AccountRepository $accountRepo,
        private readonly AccountService $accountService,
        private readonly TelegramSender $sender,
        private readonly BotTranslator $translator,
    ) {
    }

    /** @param array<string, mixed> $update */
    public function handle(int $chatId, array $update): void
    {
        $user = $this->userRepo->findByTelegramChatId($chatId);
        if (null === $user || null === $user->getFamily()) {
            $this->sender->sendMessage($chatId, $this->translator->trans('common.register_first'));

            return;
        }

        $family = $user->getFamily();
        $accounts = $this->accountRepo->findActiveByFamily($family);

        $text = $this->translator->trans('balance.title')."\n\n";

        foreach ($accounts as $account) {
            $balance = $this->accountService->getBalance($account);
            $icon = $this->translator->trans('balance.account_type.'.$account->getType()->value);
            $balanceFormatted = number_format($balance, 2, '.', ' ');
            $text .= "{$icon} *{$account->getName()}*: {$balanceFormatted} {$account->getCurrency()}\n";
        }

        $totals = $this->accountService->getTotalBalance($family);
        $text .= "\n".$this->translator->trans('balance.total').' ';

        foreach ($totals as $currency => $total) {
            $totalFormatted = number_format((float) $total, 2, '.', ' ');
            $text .= "{$totalFormatted} {$currency} ";
        }

        $this->sender->sendMessage($chatId, rtrim($text), parseMode: 'Markdown');
    }
}
