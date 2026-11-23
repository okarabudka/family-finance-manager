<?php

declare(strict_types=1);

namespace App\Telegram\Handler;

use App\Domain\Transaction\TransactionService;
use App\Entity\Category;
use App\Entity\CategoryType;
use App\Entity\Family;
use App\Entity\TransactionType;
use App\Repository\CategoryRepository;
use App\Repository\TransactionRepository;
use App\Repository\UserRepository;
use App\Telegram\BotTranslator;
use App\Telegram\TelegramSender;

class TransactionCallbackHandler
{
    public function __construct(
        private readonly UserRepository $userRepo,
        private readonly TransactionRepository $txRepo,
        private readonly CategoryRepository $categoryRepo,
        private readonly TransactionService $transactionService,
        private readonly TelegramSender $sender,
        private readonly BotTranslator $translator,
    ) {
    }

    /**
     * Handles tx:delete:{txId}, tx:category:{txId}, tx:assign:{txId}:{catId} callbacks.
     */
    public function handleCallback(int $chatId, string $callbackData): void
    {
        $user = $this->userRepo->findByTelegramChatId($chatId);
        if (null === $user || null === $user->getFamily()) {
            $this->sender->sendMessage($chatId, $this->translator->trans('common.register_first'));

            return;
        }

        // Split into at most 4 parts: tx, action, txId, optional catId
        $parts = explode(':', $callbackData, 4);

        match ($parts[1] ?? '') {
            'delete' => $this->handleDelete($chatId, $parts[2] ?? '', $user->getFamily()),
            'category' => $this->handleCategoryMenu($chatId, $parts[2] ?? '', $user->getFamily()),
            'assign' => $this->handleAssign($chatId, $parts[2] ?? '', $parts[3] ?? '', $user->getFamily()),
            default => null,
        };
    }

    private function handleDelete(int $chatId, string $txId, Family $family): void
    {
        $transaction = $this->txRepo->find($txId);
        if (null === $transaction || !$transaction->getFamily()->getId()->equals($family->getId())) {
            $this->sender->sendMessage($chatId, $this->translator->trans('common.transaction_not_found'));

            return;
        }

        $amount = number_format((float) $transaction->getAmount(), 2, '.', ' ');
        $desc = $transaction->getDescription() ?? '';
        $currency = $transaction->getCurrency();
        $this->transactionService->delete($transaction);

        $this->sender->sendMessage(
            $chatId,
            $this->translator->trans('transaction.deleted', ['%amount%' => $amount.' '.$currency]).('' !== $desc ? " — {$desc}" : ''),
        );
    }

    private function handleCategoryMenu(int $chatId, string $txId, Family $family): void
    {
        $transaction = $this->txRepo->find($txId);
        if (null === $transaction || !$transaction->getFamily()->getId()->equals($family->getId())) {
            $this->sender->sendMessage($chatId, $this->translator->trans('common.transaction_not_found'));

            return;
        }

        $catType = TransactionType::Income === $transaction->getType()
            ? CategoryType::Income
            : CategoryType::Expense;
        $categories = $this->categoryRepo->findByFamily($family, $catType);

        if (empty($categories)) {
            $this->sender->sendMessage($chatId, $this->translator->trans('transaction.no_categories'));

            return;
        }

        $rows = [];
        foreach (array_chunk($categories, 2) as $pair) {
            $rows[] = array_map(
                static fn (Category $c) => [
                    'text' => $c->getName(),
                    'callback_data' => "tx:assign:{$txId}:{$c->getId()}",
                ],
                $pair,
            );
        }

        $this->sender->sendMessage($chatId, $this->translator->trans('common.choose_category'), ['inline_keyboard' => $rows]);
    }

    private function handleAssign(int $chatId, string $txId, string $catId, Family $family): void
    {
        $transaction = $this->txRepo->find($txId);
        if (null === $transaction || !$transaction->getFamily()->getId()->equals($family->getId())) {
            $this->sender->sendMessage($chatId, $this->translator->trans('common.transaction_not_found'));

            return;
        }

        $category = $this->categoryRepo->findByFamilyAndId($family, $catId);
        if (null === $category) {
            $this->sender->sendMessage($chatId, $this->translator->trans('common.category_not_found'));

            return;
        }

        $this->transactionService->update($transaction, ['category' => $category]);
        $this->sender->sendMessage($chatId, $this->translator->trans('transaction.category_set', ['%category%' => $category->getName()]));
    }
}
