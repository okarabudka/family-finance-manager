# Шаг 5 — Импорт банковских выписок

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

## 5.3 ImportProcessor — основной сервис

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
                         ->setAmount($rawTx['amount'])
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
        return sha256(implode('|', [
            $account->getId(),
            $rawTx['date'],
            $rawTx['amount'],
            $rawTx['description'] ?? '',
            $rawTx['counterpartyIban'] ?? '',
        ]));
    }

    private function createTransactionFromImport(ImportTransaction $importTx, ImportFile $importFile): void
    {
        $amount = (float) $importTx->getAmount();
        $account = $importFile->getAccount();
        $family = $account->getFamily();
        $user = $importFile->getUploadedBy();

        if ($amount > 0) {
            $this->transactionService->addIncome(
                family: $family,
                user: $user,
                amount: $amount,
                category: $importTx->getSuggestedCategory(),
                account: $account,
                description: $importTx->getRawDescription(),
                date: $importTx->getDate(),
            );
        } else {
            $this->transactionService->addExpense(
                family: $family,
                user: $user,
                amount: abs($amount),
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

## 5.4 Парсер MT940

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

## 5.5 Парсер CSV Sparkasse

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

## 5.6 Автоопределение формата файла

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
        $line = fgets($handle);
        fclose($handle);
        return $line ?: '';
    }
}
```

---

## 5.7 Telegram-диалог импорта

```php
// src/Telegram/Handler/ImportCommandHandler.php

class ImportCommandHandler implements CommandHandlerInterface
{
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
    public function handle(int $chatId, array $update, array $state): void
    {
        $step = $state['step'] ?? 0;

        match($step) {
            0 => $this->handleAccountSelection($chatId, $update, $state),
            1 => $this->handleFileUpload($chatId, $update, $state),
        };
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

## 5.8 Подтверждение неразобранных транзакций

Когда импорт завершён и есть нераспознанные транзакции, бот отправляет их по одной:

```php
// src/Telegram/Handler/PendingImportHandler.php

class PendingImportHandler
{
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
}
```

---

## 5.9 Message и MessageHandler для очереди

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
