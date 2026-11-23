<?php

declare(strict_types=1);

namespace App\Telegram\Handler;

use App\Domain\Category\CategoryRuleService;
use App\Domain\Transaction\QuickInputParser;
use App\Domain\Transaction\TransactionService;
use App\Repository\AccountRepository;
use App\Repository\UserRepository;
use App\Telegram\BotTranslator;
use App\Telegram\Payload;
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
        private readonly BotTranslator $translator,
    ) {
    }

    /** @param array<string, mixed> $update */
    public function handle(array $update): void
    {
        $chatId = Payload::chatId($update);
        $text = Payload::text($update);

        $user = $this->userRepo->findByTelegramChatId($chatId);
        if (null === $user || null === $user->getFamily()) {
            $this->sender->sendMessage($chatId, $this->translator->trans('common.register_first'));

            return;
        }

        $parsed = $this->quickInputParser->parse($text);

        if (null === $parsed) {
            $this->sender->sendMessage(
                $chatId,
                $this->translator->trans('quick_input.not_understood'),
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
        if (null === $account) {
            $this->sender->sendMessage(
                $chatId,
                $this->translator->trans('quick_input.no_default_account'),
            );

            return;
        }

        $transaction = $this->transactionService->addExpense(
            family: $user->getFamily(),
            user: $user,
            amount: $parsed->amount,
            category: $category,
            account: $account,
            description: $parsed->description,
        );

        $categoryText = null !== $category
            ? $category->getName()
            : $this->translator->trans('quick_input.no_category');

        $amountFormatted = number_format((float) $parsed->amount, 2, '.', ' ');
        $currency = $user->getFamily()->getCurrency();

        $keyboard = [
            'inline_keyboard' => [[
                ['text' => $this->translator->trans('quick_input.button_category'), 'callback_data' => "tx:category:{$transaction->getId()}"],
                ['text' => $this->translator->trans('quick_input.button_delete'), 'callback_data' => "tx:delete:{$transaction->getId()}"],
            ]],
        ];

        $this->sender->sendMessage(
            $chatId,
            $this->translator->trans('quick_input.recorded', ['%amount%' => "{$amountFormatted} {$currency}", '%category%' => $categoryText]),
            $keyboard,
        );
    }
}
