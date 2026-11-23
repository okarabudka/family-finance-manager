<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Import;

use App\Domain\Category\CategoryRuleService;
use App\Domain\Import\ImportParserFactory;
use App\Domain\Import\ImportParserInterface;
use App\Domain\Import\ImportProcessor;
use App\Domain\Transaction\TransactionService;
use App\Entity\Account;
use App\Entity\AccountType;
use App\Entity\Category;
use App\Entity\CategoryType;
use App\Entity\Family;
use App\Entity\ImportFile;
use App\Entity\ImportStatus;
use App\Entity\User;
use App\Repository\ImportTransactionRepository;
use App\Telegram\TelegramNotifier;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

class ImportProcessorTest extends TestCase
{
    private ImportParserFactory&MockObject $parserFactory;
    private CategoryRuleService&MockObject $categoryRuleService;
    private TransactionService&MockObject $transactionService;
    private ImportTransactionRepository&MockObject $importTxRepo;
    private EntityManagerInterface&MockObject $em;
    private TelegramNotifier&MockObject $notifier;
    private ImportProcessor $processor;

    private Family $family;
    private User $user;
    private Account $account;
    private ImportFile $importFile;

    protected function setUp(): void
    {
        $this->parserFactory = $this->createMock(ImportParserFactory::class);
        $this->categoryRuleService = $this->createMock(CategoryRuleService::class);
        $this->transactionService = $this->createMock(TransactionService::class);
        $this->importTxRepo = $this->createMock(ImportTransactionRepository::class);
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->notifier = $this->createMock(TelegramNotifier::class);

        $this->processor = new ImportProcessor(
            $this->parserFactory,
            $this->categoryRuleService,
            $this->transactionService,
            $this->importTxRepo,
            $this->em,
            $this->notifier,
        );

        $this->family = new Family();
        $this->family->setName('Test Family')->setCurrency('EUR');
        $this->setId($this->family, Uuid::v4());

        $this->user = new User();
        $this->user->setName('Test')->setEmail('t@t.com')->setPassword('')->setFamily($this->family);

        $this->account = new Account();
        $this->account->setName('Main')->setType(AccountType::Checking)->setFamily($this->family)->setCurrency('EUR');
        $this->setId($this->account, Uuid::v4());

        $this->importFile = new ImportFile();
        $this->importFile
            ->setOriginalFilename('test.csv')
            ->setStoragePath('/tmp/test.csv')
            ->setFormat('csv_sparkasse')
            ->setAccount($this->account)
            ->setUploadedBy($this->user);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testProcessAutoImportsWhenCategoryRecognised(): void
    {
        $rawTxs = [
            [
                'date' => '2025-03-01',
                'amount' => -50.0,
                'description' => 'REWE Supermarkt',
                'counterpartyName' => 'REWE',
                'counterpartyIban' => null,
            ],
        ];

        $parser = $this->createMock(ImportParserInterface::class);
        $parser->method('parse')->willReturn($rawTxs);
        $this->parserFactory->method('getParser')->willReturn($parser);

        $category = new Category();
        $category->setName('Groceries')->setType(CategoryType::Expense)->setFamily($this->family);

        $this->importTxRepo->method('hashExists')->willReturn(false);
        $this->categoryRuleService->method('guessCategory')->willReturn($category);

        $this->em->expects(self::atLeastOnce())->method('persist');
        $this->em->expects(self::atLeastOnce())->method('flush');

        $this->transactionService->expects(self::once())->method('addExpense');
        $this->notifier->expects(self::once())->method('notifyImportComplete');

        $this->processor->process($this->importFile);

        self::assertSame(ImportStatus::Done, $this->importFile->getStatus());
        self::assertSame(1, $this->importFile->getImportedRows());
        self::assertSame(0, $this->importFile->getSkippedRows());
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testProcessLeavesTransactionPendingWhenNoCategoryMatch(): void
    {
        $rawTxs = [
            [
                'date' => '2025-03-01',
                'amount' => -99.99,
                'description' => 'Unknown vendor',
                'counterpartyName' => null,
                'counterpartyIban' => null,
            ],
        ];

        $parser = $this->createMock(ImportParserInterface::class);
        $parser->method('parse')->willReturn($rawTxs);
        $this->parserFactory->method('getParser')->willReturn($parser);

        $this->importTxRepo->method('hashExists')->willReturn(false);
        $this->categoryRuleService->method('guessCategory')->willReturn(null);

        $this->em->method('persist');
        $this->em->method('flush');

        $this->transactionService->expects(self::never())->method('addExpense');
        $this->transactionService->expects(self::never())->method('addIncome');

        // pendingCount = 1 should be passed to notifier
        $this->notifier->expects(self::once())
            ->method('notifyImportComplete')
            ->with($this->importFile, 1);

        $this->processor->process($this->importFile);

        self::assertSame(ImportStatus::Done, $this->importFile->getStatus());
        self::assertSame(0, $this->importFile->getImportedRows());
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testProcessSkipsDuplicateTransactions(): void
    {
        $rawTxs = [
            [
                'date' => '2025-03-01',
                'amount' => -50.0,
                'description' => 'Duplicate',
                'counterpartyName' => null,
                'counterpartyIban' => null,
            ],
        ];

        $parser = $this->createMock(ImportParserInterface::class);
        $parser->method('parse')->willReturn($rawTxs);
        $this->parserFactory->method('getParser')->willReturn($parser);

        // Simulate hash already exists (duplicate)
        $this->importTxRepo->method('hashExists')->willReturn(true);

        $this->em->method('flush');
        $this->notifier->method('notifyImportComplete');

        $this->transactionService->expects(self::never())->method('addExpense');

        $this->processor->process($this->importFile);

        self::assertSame(1, $this->importFile->getSkippedRows());
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testProcessSetsFailedStatusAndNotifiesOnException(): void
    {
        $this->parserFactory->method('getParser')
            ->willThrowException(new \RuntimeException('Parser failure'));

        $this->em->method('flush');

        $this->notifier->expects(self::once())
            ->method('notifyImportFailed')
            ->with($this->importFile, 'Parser failure');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Parser failure');

        $this->processor->process($this->importFile);

        self::assertSame(ImportStatus::Failed, $this->importFile->getStatus());
        self::assertSame('Parser failure', $this->importFile->getErrorMessage());
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testProcessHandlesPositiveAmountAsIncome(): void
    {
        $rawTxs = [
            [
                'date' => '2025-03-15',
                'amount' => 2500.0,
                'description' => 'Salary',
                'counterpartyName' => 'Employer GmbH',
                'counterpartyIban' => null,
            ],
        ];

        $parser = $this->createMock(ImportParserInterface::class);
        $parser->method('parse')->willReturn($rawTxs);
        $this->parserFactory->method('getParser')->willReturn($parser);

        $category = new Category();
        $category->setName('Income')->setType(CategoryType::Income)->setFamily($this->family);

        $this->importTxRepo->method('hashExists')->willReturn(false);
        $this->categoryRuleService->method('guessCategory')->willReturn($category);

        $this->em->method('persist');
        $this->em->method('flush');
        $this->notifier->method('notifyImportComplete');

        $this->transactionService->expects(self::once())->method('addIncome');
        $this->transactionService->expects(self::never())->method('addExpense');

        $this->processor->process($this->importFile);
    }

    private function setId(object $entity, Uuid $uuid): void
    {
        $ref = new \ReflectionProperty($entity, 'id');
        $ref->setValue($entity, $uuid);
    }
}
