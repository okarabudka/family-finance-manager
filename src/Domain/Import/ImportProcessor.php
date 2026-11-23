<?php

declare(strict_types=1);

namespace App\Domain\Import;

use App\Domain\Category\CategoryRuleService;
use App\Domain\Transaction\TransactionService;
use App\Entity\Account;
use App\Entity\ImportFile;
use App\Entity\ImportStatus;
use App\Entity\ImportTransaction;
use App\Entity\ImportTransactionStatus;
use App\Repository\ImportTransactionRepository;
use App\Telegram\TelegramNotifier;
use Doctrine\ORM\EntityManagerInterface;

class ImportProcessor
{
    public function __construct(
        private readonly ImportParserFactory $parserFactory,
        private readonly CategoryRuleService $categoryRuleService,
        private readonly TransactionService $transactionService,
        private readonly ImportTransactionRepository $importTxRepo,
        private readonly EntityManagerInterface $em,
        private readonly TelegramNotifier $notifier,
    ) {
    }

    public function process(ImportFile $importFile): void
    {
        $importFile->setStatus(ImportStatus::Processing);
        $this->em->flush();

        try {
            $parser = $this->parserFactory->getParser($importFile->getFormat());
            $rawTransactions = $parser->parse($importFile->getStoragePath());

            $importFile->setTotalRows(count($rawTransactions));

            $imported = 0;
            $skipped = 0;
            $pending = 0;

            foreach ($rawTransactions as $rawTx) {
                $hash = $this->calculateHash($rawTx, $importFile->getAccount());

                // Deduplication: skip transactions we have already imported
                if ($this->importTxRepo->hashExists($hash)) {
                    ++$skipped;
                    continue;
                }

                $importTx = new ImportTransaction();
                $importTx
                    ->setImportFile($importFile)
                    ->setAmount((string) $rawTx['amount'])
                    ->setDate(new \DateTimeImmutable($rawTx['date']))
                    ->setRawDescription($rawTx['description'])
                    ->setCounterpartyName($rawTx['counterpartyName'] ?? null)
                    ->setCounterpartyIban($rawTx['counterpartyIban'] ?? null)
                    ->setDeduplicationHash($hash);

                // Attempt automatic category assignment via rule matching
                $category = $this->categoryRuleService->guessCategory(
                    family: $importFile->getAccount()->getFamily(),
                    description: $rawTx['description'],
                    counterpartyIban: $rawTx['counterpartyIban'] ?? null,
                );

                $importTx->setSuggestedCategory($category);

                if (null !== $category) {
                    // Category recognised — create the transaction immediately
                    $importTx->setStatus(ImportTransactionStatus::AutoImported);
                    $this->createTransactionFromImport($importTx, $importFile);
                    ++$imported;
                } else {
                    // Needs manual confirmation
                    $importTx->setStatus(ImportTransactionStatus::Pending);
                    ++$pending;
                }

                $this->em->persist($importTx);
            }

            $importFile
                ->setImportedRows($imported)
                ->setSkippedRows($skipped)
                ->setStatus(ImportStatus::Done);

            $this->em->flush();

            // Notify user about the completed import
            $this->notifier->notifyImportComplete($importFile, $pending);
        } catch (\Throwable $e) {
            $importFile
                ->setStatus(ImportStatus::Failed)
                ->setErrorMessage($e->getMessage());
            $this->em->flush();
            $this->notifier->notifyImportFailed($importFile, $e->getMessage());
            throw $e;
        }
    }

    /** @param array{date: string, amount: float, description: string, counterpartyName: string|null, counterpartyIban: string|null} $rawTx */
    private function calculateHash(array $rawTx, Account $account): string
    {
        return hash('sha256', implode('|', [
            $account->getId(),
            $rawTx['date'],
            $rawTx['amount'],
            $rawTx['description'],
            $rawTx['counterpartyIban'] ?? '',
        ]));
    }

    private function createTransactionFromImport(ImportTransaction $importTx, ImportFile $importFile): void
    {
        // amount is stored as string in the entity; addIncome/addExpense also accept string
        $rawAmount = $importTx->getAmount();
        $account = $importFile->getAccount();
        $family = $account->getFamily();
        $user = $importFile->getUploadedBy();

        if ((float) $rawAmount > 0) {
            $this->transactionService->addIncome(
                family: $family,
                user: $user,
                amount: $rawAmount,
                category: $importTx->getSuggestedCategory(),
                account: $account,
                description: $importTx->getRawDescription() ?? '',
                date: $importTx->getDate(),
            );
        } else {
            $this->transactionService->addExpense(
                family: $family,
                user: $user,
                amount: ltrim($rawAmount, '-'),  // positive string without leading minus
                category: $importTx->getSuggestedCategory(),
                account: $account,
                description: $importTx->getRawDescription() ?? '',
                date: $importTx->getDate(),
            );
        }
    }
}
