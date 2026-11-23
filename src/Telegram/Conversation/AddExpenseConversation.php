<?php

declare(strict_types=1);

namespace App\Telegram\Conversation;

use App\Domain\Transaction\TransactionService;
use App\Entity\CategoryType;
use App\Repository\AccountRepository;
use App\Repository\CategoryRepository;
use App\Repository\UserRepository;
use App\Telegram\ConversationInterface;
use App\Telegram\ConversationManager;
use App\Telegram\TelegramSender;
use App\Telegram\BotTranslator;

class AddExpenseConversation implements ConversationInterface
{
    public function __construct(
        private readonly TelegramSender $sender,
        private readonly TransactionService $transactionService,
        private readonly CategoryRepository $categoryRepo,
        private readonly AccountRepository $accountRepo,
        private readonly ConversationManager $conversationManager,
        private readonly UserRepository $userRepo,
        private readonly BotTranslator $translator,
    ) {}

    /**
     * @param array<string, mixed> $update
     * @param array<string, mixed> $state
     */
    public function handle(int $chatId, array $update, array $state): void
    {
        $step         = $state['step'] ?? 0;
        $text         = $update['message']['text'] ?? '';
        $callbackData = $update['callback_query']['data'] ?? null;

        match ($step) {
            0 => $this->askAmount($chatId, $state),
            1 => $this->handleAmount($chatId, $text, $state),
            2 => $this->handleCategory($chatId, $callbackData, $state),
            3 => $this->handleAccount($chatId, $callbackData, $state),
            4 => $this->handleDescription($chatId, $text, $state),
            5 => $this->handleDate($chatId, $text, $state),
            default => null,
        };
    }

    /** @param array<string, mixed> $state */
    private function askAmount(int $chatId, array $state): void
    {
        $this->sender->sendMessage($chatId, $this->translator->trans('expense.ask_amount'));
        $state['step'] = 1;
        $this->conversationManager->updateState($chatId, $state);
    }

    /** @param array<string, mixed> $state */
    private function handleAmount(int $chatId, string $text, array $state): void
    {
        $amount = (float) str_replace(',', '.', trim($text));

        if ($amount <= 0.0) {
            $this->sender->sendMessage($chatId, $this->translator->trans('common.invalid_amount'));

            return;
        }

        $state['data']['amount'] = $amount;
        $state['step']           = 2;
        $this->conversationManager->updateState($chatId, $state);

        $user = $this->userRepo->findByTelegramChatId($chatId);
        if ($user === null || $user->getFamily() === null) {
            $this->sender->sendMessage($chatId, $this->translator->trans('common.register_first'));
            $this->conversationManager->end($chatId);

            return;
        }

        $categories = $this->categoryRepo->findByFamily($user->getFamily(), CategoryType::Expense);

        $keyboard = $this->buildCategoryKeyboard($categories);
        $this->sender->sendMessage($chatId, $this->translator->trans('common.choose_category'), $keyboard);
    }

    /** @param array<string, mixed> $state */
    private function handleCategory(int $chatId, ?string $callbackData, array $state): void
    {
        if ($callbackData === null || !str_starts_with($callbackData, 'category:')) {
            $this->sender->sendMessage($chatId, $this->translator->trans('common.choose_category_from_list'));

            return;
        }

        $categoryId              = substr($callbackData, 9);
        $state['data']['categoryId'] = $categoryId;
        $state['step']           = 3;
        $this->conversationManager->updateState($chatId, $state);

        $user = $this->userRepo->findByTelegramChatId($chatId);
        if ($user === null || $user->getFamily() === null) {
            $this->sender->sendMessage($chatId, $this->translator->trans('common.register_first'));
            $this->conversationManager->end($chatId);

            return;
        }

        $accounts = $this->accountRepo->findActiveByFamily($user->getFamily());
        $keyboard = $this->buildAccountKeyboard($accounts);
        $this->sender->sendMessage($chatId, $this->translator->trans('common.choose_account'), $keyboard);
    }

    /** @param array<string, mixed> $state */
    private function handleAccount(int $chatId, ?string $callbackData, array $state): void
    {
        if ($callbackData === null || !str_starts_with($callbackData, 'account:')) {
            $this->sender->sendMessage($chatId, $this->translator->trans('common.choose_account_from_list'));

            return;
        }

        $accountId                = substr($callbackData, 8);
        $state['data']['accountId'] = $accountId;
        $state['step']            = 4;
        $this->conversationManager->updateState($chatId, $state);

        $this->sender->sendMessage($chatId, $this->translator->trans('expense.ask_description'));
    }

    /** @param array<string, mixed> $state */
    private function handleDescription(int $chatId, string $text, array $state): void
    {
        $description = trim($text);
        $state['data']['description'] = ($description === '-') ? '' : $description;
        $state['step'] = 5;
        $this->conversationManager->updateState($chatId, $state);

        $this->sender->sendMessage(
            $chatId,
            $this->translator->trans('common.ask_date', ['%today%' => $this->translator->trans('common.today')]),
        );
    }

    /** @param array<string, mixed> $state */
    private function handleDate(int $chatId, string $text, array $state): void
    {
        $trimmed = trim($text);

        if ($trimmed === '' || \in_array(mb_strtolower($trimmed), ['today', mb_strtolower($this->translator->trans('common.today'))], true)) {
            $date = new \DateTimeImmutable();
        } else {
            $date = \DateTimeImmutable::createFromFormat('d.m.Y', $trimmed);
            if ($date === false) {
                $this->sender->sendMessage(
                    $chatId,
                    $this->translator->trans('common.invalid_date', ['%today%' => $this->translator->trans('common.today')]),
                );

                return;
            }
        }

        $state['data']['date'] = $date->format('Y-m-d');
        $this->conversationManager->updateState($chatId, $state);

        $this->createTransaction($chatId, $state);
    }

    /** @param array<string, mixed> $state */
    private function createTransaction(int $chatId, array $state): void
    {
        $user = $this->userRepo->findByTelegramChatId($chatId);
        if ($user === null || $user->getFamily() === null) {
            $this->sender->sendMessage($chatId, $this->translator->trans('common.register_first'));
            $this->conversationManager->end($chatId);

            return;
        }

        $data = $state['data'];

        $date = isset($data['date'])
            ? new \DateTimeImmutable($data['date'])
            : new \DateTimeImmutable();

        $account = $this->accountRepo->findByFamilyAndId($user->getFamily(), $data['accountId']);
        if ($account === null) {
            $this->sender->sendMessage($chatId, $this->translator->trans('expense.account_not_found'));
            $this->conversationManager->end($chatId);

            return;
        }

        $transaction = $this->transactionService->addExpense(
            family:      $user->getFamily(),
            user:        $user,
            amount:      (string) $data['amount'],
            category:    $this->categoryRepo->findByFamilyAndId($user->getFamily(), $data['categoryId']),
            account:     $account,
            description: $data['description'] ?? '',
            date:        $date,
        );

        $this->conversationManager->end($chatId);

        $categoryName    = $transaction->getCategory()?->getName() ?? $this->translator->trans('common.no_category');
        $amountFormatted = number_format((float) $transaction->getAmount(), 2, '.', ' ');

        $this->sender->sendMessage(
            $chatId,
            $this->translator->trans('expense.recorded', [
                '%amount%'   => "{$amountFormatted} {$transaction->getCurrency()}",
                '%category%' => $categoryName,
                '%date%'     => $transaction->getExecutedAt()->format('d.m.Y'),
            ]),
        );
    }

    /**
     * @param \App\Entity\Category[] $categories
     * @return array<string, mixed>
     */
    private function buildCategoryKeyboard(array $categories): array
    {
        $rows = [];
        foreach (array_chunk($categories, 2) as $rowCategories) {
            $row = [];
            foreach ($rowCategories as $category) {
                $row[] = [
                    'text'          => $category->getName(),
                    'callback_data' => 'category:' . $category->getId(),
                ];
            }
            $rows[] = $row;
        }

        return ['inline_keyboard' => $rows];
    }

    /**
     * @param \App\Entity\Account[] $accounts
     * @return array<string, mixed>
     */
    private function buildAccountKeyboard(array $accounts): array
    {
        $rows = [];
        foreach (array_chunk($accounts, 2) as $rowAccounts) {
            $row = [];
            foreach ($rowAccounts as $account) {
                $row[] = [
                    'text'          => $account->getName(),
                    'callback_data' => 'account:' . $account->getId(),
                ];
            }
            $rows[] = $row;
        }

        return ['inline_keyboard' => $rows];
    }
}
