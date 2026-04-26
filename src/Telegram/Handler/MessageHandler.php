<?php

declare(strict_types=1);

namespace App\Telegram\Handler;

use App\Domain\Category\CategoryRuleService;
use App\Domain\Transaction\QuickInputParser;
use App\Domain\Transaction\TransactionService;
use App\Repository\AccountRepository;
use App\Repository\UserRepository;
use App\Telegram\TelegramSender;

class MessageHandler
{
    public function __construct(
        private readonly UserRepository $userRepo,
        private readonly QuickInputParser $quickInputParser,
        private readonly CategoryRuleService $categoryRuleService,
        private readonly AccountRepository $accountRepo,
        private readonly TransactionService $transactionService,
        private readonly TelegramSender $sender,
    ) {}

    /** @param array<string, mixed> $update */
    public function handle(array $update): void
    {
        $chatId = (int) ($update['message']['chat']['id'] ?? 0);
        $text   = $update['message']['text'] ?? '';

        $user = $this->userRepo->findByTelegramChatId($chatId);
        if ($user === null || $user->getFamily() === null) {
            $this->sender->sendMessage($chatId, 'Please register first: /start');

            return;
        }

        $parsed = $this->quickInputParser->parse($text);

        if ($parsed === null) {
            $this->sender->sendMessage(
                $chatId,
                "Not understood. Enter an expense as:\n`50 groceries`\n`150.50 restaurant`\n\nOr use /help",
                parseMode: 'Markdown',
            );

            return;
        }

        // Guess a category from the description
        $category = $this->categoryRuleService->guessCategory(
            $user->getFamily(),
            $parsed->description,
        );

        // Use the default account for quick entry
        $account = $this->accountRepo->findDefaultForFamily($user->getFamily());
        if ($account === null) {
            $this->sender->sendMessage(
                $chatId,
                'Please set a default account in the web interface before using quick input.',
            );

            return;
        }

        $transaction = $this->transactionService->addExpense(
            family:      $user->getFamily(),
            user:        $user,
            amount:      $parsed->amount,
            category:    $category,
            account:     $account,
            description: $parsed->description,
        );

        $categoryText = $category !== null
            ? $category->getName()
            : 'No category (tap to assign)';

        $amountFormatted = number_format((float) $parsed->amount, 2, '.', ' ');
        $currency        = $user->getFamily()->getCurrency();

        $keyboard = [
            'inline_keyboard' => [[
                ['text' => 'Category', 'callback_data' => "tx:category:{$transaction->getId()}"],
                ['text' => 'Delete',   'callback_data' => "tx:delete:{$transaction->getId()}"],
            ]],
        ];

        $this->sender->sendMessage(
            $chatId,
            "Recorded!\n{$amountFormatted} {$currency}\n{$categoryText}",
            $keyboard,
        );
    }
}
