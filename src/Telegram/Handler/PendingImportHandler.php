<?php

declare(strict_types=1);

namespace App\Telegram\Handler;

use App\Domain\Transaction\TransactionService;
use App\Entity\Category;
use App\Entity\CategoryType;
use App\Entity\Family;
use App\Entity\ImportFile;
use App\Entity\ImportTransaction;
use App\Entity\ImportTransactionStatus;
use App\Entity\User;
use App\Repository\CategoryRepository;
use App\Repository\ImportTransactionRepository;
use App\Repository\UserRepository;
use App\Telegram\TelegramSender;
use Doctrine\ORM\EntityManagerInterface;

class PendingImportHandler
{
    public function __construct(
        private readonly ImportTransactionRepository $importTxRepo,
        private readonly TelegramSender $sender,
        private readonly CategoryRepository $categoryRepo,
        private readonly UserRepository $userRepo,
        private readonly TransactionService $transactionService,
        private readonly EntityManagerInterface $em,
    ) {}

    /**
     * Sends the next pending ImportTransaction to the user for manual categorisation.
     * If no pending transactions remain, sends a completion message.
     */
    public function sendNextPending(int $chatId, ImportFile $importFile): void
    {
        $pending = $this->importTxRepo->findFirstPending($importFile);

        if ($pending === null) {
            $this->sender->sendMessage($chatId, 'All transactions from the statement have been processed!');

            return;
        }

        $amount = (float) $pending->getAmount();
        $sign   = $amount > 0 ? '+' : '';

        $text = "*Unrecognised transaction*\n\n"
            . "Date: {$pending->getDate()->format('d.m.Y')}\n"
            . "Amount: {$sign}" . number_format($amount, 2, '.', ' ') . " EUR\n"
            . "Description: {$pending->getRawDescription()}\n";

        if ($pending->getCounterpartyName() !== null) {
            $text .= "Counterparty: {$pending->getCounterpartyName()}\n";
        }

        $text .= "\nSelect a category or skip:";

        $family     = $importFile->getAccount()->getFamily();
        $categories = $this->categoryRepo->findByFamily($family, CategoryType::Expense);
        $keyboard   = $this->buildCategoryKeyboard($categories, $pending);

        // Add skip button at the bottom
        $keyboard['inline_keyboard'][] = [
            ['text' => 'Skip', 'callback_data' => 'pending:skip:' . $pending->getId()],
        ];

        $this->sender->sendMessage($chatId, $text, $keyboard, 'Markdown');
    }

    /**
     * Handles a pending:category:{txId}:{catId} or pending:skip:{txId} callback.
     */
    public function handleCallback(int $chatId, string $callbackData): void
    {
        $user = $this->userRepo->findByTelegramChatId($chatId);
        if ($user === null || $user->getFamily() === null) {
            $this->sender->sendMessage($chatId, 'Please register first: /start');

            return;
        }

        $parts = explode(':', $callbackData, 4);

        if (($parts[1] ?? '') === 'skip' && isset($parts[2])) {
            $this->handleSkip($chatId, $parts[2], $user->getFamily());
        } elseif (($parts[1] ?? '') === 'category' && isset($parts[2], $parts[3])) {
            $this->handleCategoryAssign($chatId, $parts[2], $parts[3], $user);
        }
    }

    private function handleSkip(int $chatId, string $txId, Family $family): void
    {
        $importTx = $this->loadImportTransactionForFamily($txId, $family);
        if ($importTx === null) {
            $this->sender->sendMessage($chatId, 'Transaction not found.');

            return;
        }

        $importTx->setStatus(ImportTransactionStatus::Skipped);
        $this->em->flush();

        $this->sendNextPending($chatId, $importTx->getImportFile());
    }

    private function handleCategoryAssign(int $chatId, string $txId, string $catId, User $user): void
    {
        $family   = $user->getFamily();
        $importTx = $this->loadImportTransactionForFamily($txId, $family);
        if ($importTx === null) {
            $this->sender->sendMessage($chatId, 'Transaction not found.');

            return;
        }

        $category = $this->categoryRepo->findByFamilyAndId($family, $catId);
        if ($category === null) {
            $this->sender->sendMessage($chatId, 'Category not found.');

            return;
        }

        $amount  = (float) $importTx->getAmount();
        $account = $importTx->getImportFile()->getAccount();

        if ($amount >= 0) {
            $this->transactionService->addIncome(
                family:      $family,
                user:        $user,
                amount:      (string) abs($amount),
                category:    $category,
                account:     $account,
                description: $importTx->getRawDescription() ?? '',
                date:        $importTx->getDate(),
            );
        } else {
            $this->transactionService->addExpense(
                family:      $family,
                user:        $user,
                amount:      (string) abs($amount),
                category:    $category,
                account:     $account,
                description: $importTx->getRawDescription() ?? '',
                date:        $importTx->getDate(),
            );
        }

        $importTx->setStatus(ImportTransactionStatus::Confirmed);
        $this->em->flush();

        $this->sendNextPending($chatId, $importTx->getImportFile());
    }

    private function loadImportTransactionForFamily(string $txId, Family $family): ?ImportTransaction
    {
        $importTx = $this->importTxRepo->find($txId);
        if ($importTx === null) {
            return null;
        }

        $txFamilyId = (string) $importTx->getImportFile()->getAccount()->getFamily()->getId();
        if ($txFamilyId !== (string) $family->getId()) {
            return null;
        }

        return $importTx;
    }

    /**
     * @param Category[] $categories
     * @return array<string, mixed>
     */
    private function buildCategoryKeyboard(array $categories, ImportTransaction $pending): array
    {
        $rows = [];
        foreach (array_chunk($categories, 2) as $pair) {
            $rows[] = array_map(
                static fn(Category $c) => [
                    'text'          => $c->getName(),
                    'callback_data' => 'pending:category:' . $pending->getId() . ':' . $c->getId(),
                ],
                $pair,
            );
        }

        return ['inline_keyboard' => $rows];
    }
}
