# Шаг 5 — Импорт банковских выписок

> **Статус: ✅ РЕАЛИЗОВАНО**

## Цель
Реализовать загрузку и парсинг выписок из Sparkasse (форматы MT940 и CSV), автоматическую категоризацию и интерфейс подтверждения нераспознанных транзакций.

---

## 5.1 Поддерживаемые форматы

| Формат | Банк | Описание |
|---|---|---|
| MT940 (.sta, .txt) | Sparkasse, все немецкие банки | Стандарт SWIFT для банковских выписок |
| CSV Sparkasse | Sparkasse | Экспорт из Online-Banking |
| CSV SEPA | Большинство европейских банков | Общий формат |

---

## 5.2 Процесс импорта

```
Пользователь отправляет файл боту
        │
        ▼
ImportFileHandler (сохраняет файл, создаёт ImportFile)
        │
        ▼
ProcessImportMessage → Symfony Messenger (очередь)
        │
        ▼
ImportProcessor:
    1. Определяет формат файла
    2. Парсит транзакции
    3. Проверяет дубликаты (deduplication hash)
    4. Применяет правила категоризации
    5. Создаёт ImportTransaction для каждой строки
        │
        ├── Автоматически распознанные → ImportTransactionStatus::AutoImported
        │   └── Сразу создаёт Transaction
        │
        └── Нераспознанные → ImportTransactionStatus::Pending
            └── Бот предлагает подтвердить по одной
```

---

## 5.3 ImportParserInterface

```php
// src/Domain/Import/Parser/ImportParserInterface.php

interface ImportParserInterface
{
    /**
     * Парсит файл выписки и возвращает массив сырых транзакций.
     * Каждый элемент: ['date', 'amount', 'description', 'counterpartyName', 'counterpartyIban']
     *
     * @return array<int, array{date: string, amount: float, description: string, counterpartyName: ?string, counterpartyIban: ?string}>
     */
    public function parse(string $filePath): array;

    public function supports(string $format): bool;
}
```

---

## 5.4 ImportParserFactory

```php
// src/Domain/Import/ImportParserFactory.php

class ImportParserFactory
{
    /** @param iterable<ImportParserInterface> $parsers */
    public function __construct(private readonly iterable $parsers) {}

    public function getParser(string $format): ImportParserInterface
    {
        foreach ($this->parsers as $parser) {
            if ($parser->supports($format)) {
                return $parser;
            }
        }
        throw new UnsupportedFormatException("Нет парсера для формата: {$format}");
    }
}

// Регистрация в services.yaml:
// App\Domain\Import\ImportParserFactory:
//   arguments:
//     $parsers: !tagged_iterator app.import_parser
//
// App\Domain\Import\Parser\Mt940Parser:
//   tags: ['app.import_parser']
// App\Domain\Import\Parser\SparkasseCsvParser:
//   tags: ['app.import_parser']
```

---

## 5.4.1 TelegramFileDownloader

```php
// src/Telegram/TelegramFileDownloader.php

class TelegramFileDownloader
{
    private const API_BASE = 'https://api.telegram.org/bot';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly string $botToken,
        private readonly string $storagePath,
    ) {}

    public function download(string $fileId, string $originalName): string
    {
        // Получаем путь файла на серверах Telegram
        $response = $this->httpClient->request(
            'GET',
            self::API_BASE . $this->botToken . "/getFile?file_id={$fileId}"
        );
        $filePath = $response->toArray()['result']['file_path'];

        // Скачиваем содержимое
        $content = $this->httpClient->request(
            'GET',
            "https://api.telegram.org/file/bot{$this->botToken}/{$filePath}"
        )->getContent();

        // Сохраняем локально
        $localPath = $this->storagePath . '/imports/' . uniqid() . '_' . basename($originalName);
        file_put_contents($localPath, $content);

        return $localPath;
    }
}
```

---

## 5.4.2 ImportTransactionRepository

```php
// src/Repository/ImportTransactionRepository.php

class ImportTransactionRepository extends ServiceEntityRepository
{
    public function hashExists(string $hash): bool
    {
        return (int) $this->createQueryBuilder('it')
            ->select('COUNT(it.id)')
            ->where('it.deduplicationHash = :hash')
            ->setParameter('hash', $hash)
            ->getQuery()
            ->getSingleScalarResult() > 0;
    }

    public function findFirstPending(Family $family): ?ImportTransaction
    {
        return $this->createQueryBuilder('it')
            ->join('it.importFile', 'f')
            ->join('f.account', 'a')
            ->where('a.family = :family')
            ->andWhere('it.status = :status')
            ->setParameter('family', $family)
            ->setParameter('status', ImportTransactionStatus::Pending)
            ->orderBy('it.date', 'ASC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function countPending(Family $family): int
    {
        return (int) $this->createQueryBuilder('it')
            ->select('COUNT(it.id)')
            ->join('it.importFile', 'f')
            ->join('f.account', 'a')
            ->where('a.family = :family')
            ->andWhere('it.status = :status')
            ->setParameter('family', $family)
            ->setParameter('status', ImportTransactionStatus::Pending)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
```

---

## 5.5 ImportProcessor — основной сервис

```php
// src/Domain/Import/ImportProcessor.php

class ImportProcessor
{
    public function __construct(
        private readonly ImportParserFactory $parserFactory,
        private readonly CategoryRuleService $categoryRuleService,
        private readonly TransactionService $transactionService,
        private readonly ImportTransactionRepository $importTxRepo,
        private readonly EntityManagerInterface $em,
        private readonly TelegramNotifier $notifier,
    ) {}

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

                // Проверка дубликатов
                if ($this->importTxRepo->hashExists($hash)) {
                    $skipped++;
                    continue;
                }

                $importTx = new ImportTransaction();
                $importTx->setImportFile($importFile)
                         ->setAmount((string) $rawTx['amount'])  // парсер возвращает float, entity хранит string
                         ->setDate(new \DateTimeImmutable($rawTx['date']))
                         ->setRawDescription($rawTx['description'] ?? '')
                         ->setCounterpartyName($rawTx['counterpartyName'] ?? null)
                         ->setCounterpartyIban($rawTx['counterpartyIban'] ?? null)
                         ->setDeduplicationHash($hash);

                // Пробуем угадать категорию
                $category = $this->categoryRuleService->guessCategory(
                    family: $importFile->getAccount()->getFamily(),
                    description: $rawTx['description'] ?? '',
                    counterpartyIban: $rawTx['counterpartyIban'] ?? null,
                );

                $importTx->setSuggestedCategory($category);

                if ($category !== null) {
                    // Автоматически создаём транзакцию
                    $importTx->setStatus(ImportTransactionStatus::AutoImported);
                    $this->createTransactionFromImport($importTx, $importFile);
                    $imported++;
                } else {
                    $importTx->setStatus(ImportTransactionStatus::Pending);
                    $pending++;
                }

                $this->em->persist($importTx);
            }

            $importFile->setImportedRows($imported)
                       ->setSkippedRows($skipped)
                       ->setStatus(ImportStatus::Done);

            $this->em->flush();

            // Уведомляем пользователя о результате
            $this->notifier->notifyImportComplete($importFile, $pending);

        } catch (\Exception $e) {
            $importFile->setStatus(ImportStatus::Failed)
                       ->setErrorMessage($e->getMessage());
            $this->em->flush();
            throw $e;
        }
    }

    private function calculateHash(array $rawTx, Account $account): string
    {
        // sha256() в PHP не существует — используем hash()
        return hash('sha256', implode('|', [
            $account->getId(),
            $rawTx['date'],
            $rawTx['amount'],
            $rawTx['description'] ?? '',
            $rawTx['counterpartyIban'] ?? '',
        ]));
    }

    private function createTransactionFromImport(ImportTransaction $importTx, ImportFile $importFile): void
    {
        // amount — строка из БД; addIncome/addExpense принимают string $amount
        $rawAmount = $importTx->getAmount(); // string
        $account   = $importFile->getAccount();
        $family    = $account->getFamily();
        $user      = $importFile->getUploadedBy();

        if ((float) $rawAmount > 0) {
            $this->transactionService->addIncome(
                family: $family,
                user: $user,
                amount: $rawAmount,                          // string ✓
                category: $importTx->getSuggestedCategory(),
                account: $account,
                description: $importTx->getRawDescription(),
                date: $importTx->getDate(),
            );
        } else {
            $this->transactionService->addExpense(
                family: $family,
                user: $user,
                amount: ltrim($rawAmount, '-'),              // string без минуса ✓
                category: $importTx->getSuggestedCategory(),
                account: $account,
                description: $importTx->getRawDescription(),
                date: $importTx->getDate(),
            );
        }
    }
}
```

---

## 5.5 Парсер MT940

```php
// src/Domain/Import/Parser/Mt940Parser.php

use Codelicious\Mt940\Parser as Mt940LibParser;

class Mt940Parser implements ImportParserInterface
{
    public function parse(string $filePath): array
    {
        $parser = new Mt940LibParser();
        $statements = $parser->parse(file_get_contents($filePath));

        $transactions = [];

        foreach ($statements as $statement) {
            foreach ($statement->getTransactions() as $tx) {
                $transactions[] = [
                    'date'             => $tx->getValueDate()->format('Y-m-d'),
                    'amount'           => $tx->getPrice(),  // отрицательный для расходов
                    'description'      => $this->cleanDescription($tx->getDescription()),
                    'counterpartyName' => $tx->getName(),
                    'counterpartyIban' => $tx->getIban(),
                ];
            }
        }

        return $transactions;
    }

    private function cleanDescription(string $raw): string
    {
        // MT940 упаковывает данные через специальные коды SVWZ+, EREF+, KREF+ и т.д.
        // Извлекаем только человекочитаемую часть
        if (preg_match('/SVWZ\+([^+]+)/', $raw, $m)) {
            return trim($m[1]);
        }
        return trim(preg_replace('/[A-Z]{4}\+/', ' ', $raw));
    }

    public function supports(string $format): bool
    {
        return $format === 'mt940';
    }
}
```

---

## 5.6 Парсер CSV Sparkasse

```php
// src/Domain/Import/Parser/SparkasseCsvParser.php

use League\Csv\Reader;

class SparkasseCsvParser implements ImportParserInterface
{
    // Sparkasse CSV формат:
    // "Auftragskonto";"Buchungstag";"Valutadatum";"Buchungstext";
    // "Auftraggeber / Beguenstigter";"Verwendungszweck";"Glaeubiger ID";
    // "Mandatsreferenz";"Kundenreferenz (End-to-End)";"Sammlerreferenz";
    // "Lastschrift Ursprungsbetrag";"Auslagenersatz Ruecklastschrift";
    // "Betrag";"Waehrung";"Glaeubiger ID";"Mandatsreferenz";...

    public function parse(string $filePath): array
    {
        $content = file_get_contents($filePath);

        // Sparkasse экспортирует в Windows-1252 или UTF-8 с BOM
        if (!mb_check_encoding($content, 'UTF-8')) {
            $content = mb_convert_encoding($content, 'UTF-8', 'Windows-1252');
        }
        // Убираем BOM
        $content = ltrim($content, "\xEF\xBB\xBF");

        $csv = Reader::createFromString($content);
        $csv->setDelimiter(';');
        $csv->setEnclosure('"');

        // Sparkasse может иметь заголовок на строке 1 (не 0)
        $csv->setHeaderOffset(0);

        $transactions = [];
        foreach ($csv->getRecords() as $record) {
            $amountStr = $record['Betrag'] ?? '';
            if (empty($amountStr)) continue;

            // Немецкий формат чисел: 1.234,56 → 1234.56
            $amount = (float) str_replace(
                ['.', ','],
                ['', '.'],
                $amountStr
            );

            // Парсим дату: DD.MM.YY или DD.MM.YYYY
            $dateStr = $record['Buchungstag'] ?? $record['Valutadatum'] ?? '';
            $date = \DateTime::createFromFormat('d.m.y', $dateStr)
                ?: \DateTime::createFromFormat('d.m.Y', $dateStr);

            if (!$date) continue;

            $transactions[] = [
                'date'             => $date->format('Y-m-d'),
                'amount'           => $amount,
                'description'      => trim($record['Verwendungszweck'] ?? ''),
                'counterpartyName' => trim($record['Auftraggeber / Beguenstigter'] ?? ''),
                'counterpartyIban' => null, // в CSV Sparkasse IBAN не всегда есть
            ];
        }

        return $transactions;
    }

    public function supports(string $format): bool
    {
        return $format === 'csv_sparkasse';
    }
}
```

---

## 5.7 Автоопределение формата файла

```php
// src/Domain/Import/FormatDetector.php

class FormatDetector
{
    public function detect(string $filePath, string $originalFilename): string
    {
        $extension = strtolower(pathinfo($originalFilename, PATHINFO_EXTENSION));
        $firstLine = $this->readFirstLine($filePath);

        // MT940 всегда начинается с :20:
        if (str_starts_with(trim($firstLine), ':20:')) {
            return 'mt940';
        }

        // STA файлы — тоже MT940
        if (in_array($extension, ['sta', 'mt940'])) {
            return 'mt940';
        }

        if ($extension === 'csv') {
            // Sparkasse CSV имеет характерный заголовок
            if (str_contains($firstLine, 'Auftragskonto') || str_contains($firstLine, 'Buchungstag')) {
                return 'csv_sparkasse';
            }
            return 'csv_generic';
        }

        throw new UnsupportedFormatException("Неизвестный формат файла: {$originalFilename}");
    }

    private function readFirstLine(string $path): string
    {
        $handle = fopen($path, 'r');
        if ($handle === false) {
            throw new \RuntimeException("Не удалось открыть файл: {$path}");
        }
        $line = fgets($handle);
        fclose($handle);

        if (!$line) {
            return '';
        }

        // Убираем BOM (UTF-8, UTF-16) и нормализуем кодировку для сравнения
        $line = ltrim($line, "\xEF\xBB\xBF\xFF\xFE");
        if (!mb_check_encoding($line, 'UTF-8')) {
            $line = mb_convert_encoding($line, 'UTF-8', 'Windows-1252');
        }

        return $line;
    }
}
```

---

## 5.8 Telegram-диалог импорта

```php
// src/Telegram/Handler/ImportCommandHandler.php

class ImportCommandHandler implements CommandHandlerInterface
{
    public function __construct(
        private readonly TelegramSender $sender,
        private readonly ConversationManager $conversationManager,
        private readonly UserRepository $userRepo,
        private readonly AccountRepository $accountRepo,
    ) {}

    public function handle(int $chatId, array $update): void
    {
        $user = $this->userRepo->findByTelegramChatId($chatId);
        $accounts = $this->accountRepo->findActiveByFamily($user->getFamily());

        // Просим выбрать счёт
        $keyboard = [
            'inline_keyboard' => array_map(
                fn(Account $a) => [['text' => $a->getName(), 'callback_data' => "import:account:{$a->getId()}"]],
                $accounts
            )
        ];

        $this->sender->sendMessage(
            $chatId,
            "📤 *Импорт выписки*\n\nВыберите счёт, к которому относится выписка:",
            $keyboard,
            'Markdown'
        );

        $this->conversationManager->start($chatId, ImportConversation::class);
    }
}
```

```php
// src/Telegram/Conversation/ImportConversation.php

class ImportConversation implements ConversationInterface
{
    public function __construct(
        private readonly TelegramSender $sender,
        private readonly ConversationManager $conversationManager,
        private readonly TelegramFileDownloader $telegramFileDownloader,
        private readonly FormatDetector $formatDetector,
        private readonly UserRepository $userRepo,
        private readonly AccountRepository $accountRepo,
        private readonly EntityManagerInterface $em,
        private readonly MessageBusInterface $messageBus,
    ) {}

    public function handle(int $chatId, array $update, array $state): void
    {
        $step = $state['step'] ?? 0;

        match($step) {
            0 => $this->handleAccountSelection($chatId, $update, $state),
            1 => $this->handleFileUpload($chatId, $update, $state),
        };
    }

    private function handleAccountSelection(int $chatId, array $update, array $state): void
    {
        $callbackData = $update['callback_query']['data'] ?? null;

        if (!$callbackData || !str_starts_with($callbackData, 'import:account:')) {
            $this->sender->sendMessage($chatId, '❌ Выберите счёт из предложенных кнопок.');
            return;
        }

        $accountId = substr($callbackData, strlen('import:account:'));
        $state['data']['accountId'] = $accountId;
        $state['step'] = 1;
        $this->conversationManager->updateState($chatId, $state);

        $this->sender->sendMessage(
            $chatId,
            '📎 Теперь отправьте файл выписки (MT940, .sta или CSV из Sparkasse).'
        );
    }

    private function handleFileUpload(int $chatId, array $update, array $state): void
    {
        $document = $update['message']['document'] ?? null;

        if (!$document) {
            $this->sender->sendMessage($chatId, '📎 Пожалуйста, отправьте файл выписки (MT940 или CSV).');
            return;
        }

        // Скачиваем файл от Telegram
        $fileId = $document['file_id'];
        $fileName = $document['file_name'];
        $localPath = $this->telegramFileDownloader->download($fileId, $fileName);

        // Определяем формат
        $format = $this->formatDetector->detect($localPath, $fileName);

        // Создаём ImportFile
        $user = $this->userRepo->findByTelegramChatId($chatId);
        $account = $this->accountRepo->find($state['data']['accountId']);

        if (!$user || !$account) {
            $this->sender->sendMessage($chatId, '❌ Ошибка: счёт не найден. Начните заново: /import');
            $this->conversationManager->end($chatId);
            return;
        }

        $importFile = new ImportFile();
        $importFile->setOriginalFilename($fileName)
                   ->setStoragePath($localPath)
                   ->setFormat($format)
                   ->setAccount($account)
                   ->setUploadedBy($user)
                   ->setCreatedAt(new \DateTimeImmutable());

        $this->em->persist($importFile);
        $this->em->flush();

        // Отправляем в очередь на обработку
        $this->messageBus->dispatch(new ProcessImportMessage($importFile->getId()));

        $this->conversationManager->end($chatId);
        $this->sender->sendMessage(
            $chatId,
            "⏳ Файл получен! Обрабатываю...\n\nКак только всё будет готово — пришлю результат."
        );
    }
}
```

---

## 5.9 Подтверждение неразобранных транзакций

Когда импорт завершён и есть нераспознанные транзакции, бот отправляет их по одной:

```php
// src/Telegram/Handler/PendingImportHandler.php

class PendingImportHandler
{
    public function __construct(
        private readonly ImportTransactionRepository $importTxRepo,
        private readonly TelegramSender $sender,
        private readonly CategoryRepository $categoryRepo,
        private readonly AccountRepository $accountRepo,
    ) {}

    public function sendNextPending(int $chatId, Family $family): void
    {
        $pending = $this->importTxRepo->findFirstPending($family);

        if (!$pending) {
            $this->sender->sendMessage($chatId, '✅ Все транзакции из выписки обработаны!');
            return;
        }

        $amount = (float) $pending->getAmount();
        $sign = $amount > 0 ? '+' : '';
        $text = "❓ *Нераспознанная транзакция*\n\n"
            . "📅 {$pending->getDate()->format('d.m.Y')}\n"
            . "💶 {$sign}{$amount} €\n"
            . "📝 {$pending->getRawDescription()}\n";

        if ($pending->getCounterpartyName()) {
            $text .= "👤 {$pending->getCounterpartyName()}\n";
        }

        $text .= "\nВыберите категорию или пропустите:";

        // Кнопки категорий + пропустить
        $categories = $this->categoryRepo->findByFamily($family, CategoryType::Expense);
        $keyboard = $this->buildCategoryKeyboard($categories, $pending);
        $keyboard['inline_keyboard'][] = [
            ['text' => '⏭ Пропустить', 'callback_data' => "pending:skip:{$pending->getId()}"],
        ];

        $this->sender->sendMessage($chatId, $text, $keyboard, 'Markdown');
    }

    private function buildCategoryKeyboard(array $categories, ImportTransaction $pending): array
    {
        $rows = [];
        foreach (array_chunk($categories, 2) as $pair) {
            $rows[] = array_map(
                fn(Category $c) => [
                    'text'          => $c->getName(),
                    'callback_data' => "pending:category:{$pending->getId()}:{$c->getId()}",
                ],
                $pair
            );
        }

        return ['inline_keyboard' => $rows];
    }
}
```

---

## 5.10 Message и MessageHandler для очереди

```php
// src/Message/ProcessImportMessage.php
readonly class ProcessImportMessage
{
    public function __construct(public string $importFileId) {}
}

// src/MessageHandler/ProcessImportMessageHandler.php
#[AsMessageHandler]
class ProcessImportMessageHandler
{
    public function __construct(
        private readonly ImportFileRepository $repo,
        private readonly ImportProcessor $processor,
    ) {}

    public function __invoke(ProcessImportMessage $message): void
    {
        $importFile = $this->repo->find($message->importFileId);
        if (!$importFile) return;

        $this->processor->process($importFile);
    }
}
```

---

## Результат шага 5

- Парсеры MT940 и Sparkasse CSV
- Автоопределение формата файла
- Дедупликация по хэшу
- Автоматическая категоризация распознанных транзакций
- Очередь через Symfony Messenger (обработка в фоне)
- Telegram-диалог для загрузки файла
- Предложение подтвердить нераспознанные транзакции по одной

## Следующий шаг

→ [06-analytics.md](06-analytics.md) — Аналитика, графики и предложения по оптимизации
