# Шаг 4 — Telegram-бот

## Цель
Реализовать Telegram-бота как основной пользовательский интерфейс: команды, интерактивные диалоги, клавиатуры.

---

## 4.1 Архитектура бота

```
Telegram → HTTPS Webhook → TelegramWebhookController
                                    │
                                    ▼
                           UpdateDispatcher
                          /        |         \
                   CommandHandler  MessageHandler  CallbackHandler
                   (/add, /report) (текст)        (кнопки)
                         │
                    ConversationManager  ← Redis (хранит состояние диалога)
                         │
                    Domain Services (TransactionService и др.)
                         │
                    TelegramSender → Telegram Bot API
```

---

## 4.2 Webhook-контроллер

```php
// src/Controller/Telegram/WebhookController.php

#[Route('/telegram/webhook', name: 'telegram_webhook', methods: ['POST'])]
class WebhookController extends AbstractController
{
    public function __construct(
        private readonly UpdateDispatcher $dispatcher,
        private readonly string $webhookSecret,
    ) {}

    public function __invoke(Request $request): Response
    {
        // Проверяем секрет из заголовка (безопасность)
        $secret = $request->headers->get('X-Telegram-Bot-Api-Secret-Token');
        if ($secret !== $this->webhookSecret) {
            return new Response('Forbidden', 403);
        }

        $update = json_decode($request->getContent(), true);

        if (empty($update)) {
            return new Response('Bad Request', 400);
        }

        $this->dispatcher->dispatch($update);

        return new Response('OK', 200);
    }
}
```

---

## 4.3 UpdateDispatcher — маршрутизация сообщений

```php
// src/Telegram/UpdateDispatcher.php

class UpdateDispatcher
{
    public function __construct(
        private readonly CommandRegistry $commandRegistry,
        private readonly ConversationManager $conversationManager,
        private readonly MessageHandler $messageHandler,
        private readonly CallbackHandler $callbackHandler,
    ) {}

    public function dispatch(array $update): void
    {
        $chatId = $this->extractChatId($update);

        if (isset($update['callback_query'])) {
            $this->callbackHandler->handle($update['callback_query']);
            return;
        }

        $text = $update['message']['text'] ?? '';

        // Проверяем, есть ли активный диалог
        if ($this->conversationManager->hasActiveConversation($chatId)) {
            $this->conversationManager->continue($chatId, $update);
            return;
        }

        // Проверяем команды (/add, /report и т.д.)
        if (str_starts_with($text, '/')) {
            $command = explode(' ', ltrim($text, '/'))[0];
            $command = explode('@', $command)[0]; // убираем @botname
            $this->commandRegistry->execute($command, $update);
            return;
        }

        // Иначе — обрабатываем как быстрый ввод транзакции
        $this->messageHandler->handle($update);
    }

    private function extractChatId(array $update): int
    {
        return $update['message']['chat']['id']
            ?? $update['callback_query']['message']['chat']['id']
            ?? 0;
    }
}
```

---

## 4.4 ConversationManager — многошаговые диалоги

Состояние диалога хранится в Redis (TTL 30 минут).

```php
// src/Telegram/Conversation/ConversationManager.php

class ConversationManager
{
    private const TTL = 1800; // 30 минут

    public function __construct(
        private readonly CacheInterface $cache,
        private readonly ConversationRegistry $registry,
    ) {}

    public function start(int $chatId, string $conversationClass, array $data = []): void
    {
        $state = [
            'class' => $conversationClass,
            'step' => 0,
            'data' => $data,
        ];
        $this->cache->set("conversation_{$chatId}", $state, self::TTL);
    }

    public function hasActiveConversation(int $chatId): bool
    {
        return $this->cache->has("conversation_{$chatId}");
    }

    public function continue(int $chatId, array $update): void
    {
        $state = $this->cache->get("conversation_{$chatId}");
        $conversation = $this->registry->get($state['class']);
        $conversation->handle($chatId, $update, $state);
    }

    public function updateState(int $chatId, array $state): void
    {
        $this->cache->set("conversation_{$chatId}", $state, self::TTL);
    }

    public function end(int $chatId): void
    {
        $this->cache->delete("conversation_{$chatId}");
    }
}
```

---

## 4.5 Диалог добавления расхода

```php
// src/Telegram/Conversation/AddExpenseConversation.php

class AddExpenseConversation implements ConversationInterface
{
    public function __construct(
        private readonly TelegramSender $sender,
        private readonly TransactionService $transactionService,
        private readonly CategoryRepository $categoryRepo,
        private readonly AccountRepository $accountRepo,
        private readonly ConversationManager $conversationManager,
        private readonly UserRepository $userRepo,
    ) {}

    public function handle(int $chatId, array $update, array $state): void
    {
        $step = $state['step'];
        $text = $update['message']['text'] ?? '';
        $callbackData = $update['callback_query']['data'] ?? null;

        match($step) {
            0 => $this->askAmount($chatId, $state),
            1 => $this->handleAmount($chatId, $text, $state),
            2 => $this->handleCategory($chatId, $callbackData, $state),
            3 => $this->handleAccount($chatId, $callbackData, $state),
            4 => $this->handleDate($chatId, $text, $state),
        };
    }

    private function askAmount(int $chatId, array $state): void
    {
        $this->sender->sendMessage($chatId, '💸 Введите сумму расхода:');
        $state['step'] = 1;
        $this->conversationManager->updateState($chatId, $state);
    }

    private function handleAmount(int $chatId, string $text, array $state): void
    {
        $amount = (float) str_replace(',', '.', $text);

        if ($amount <= 0) {
            $this->sender->sendMessage($chatId, '❌ Некорректная сумма. Введите число больше 0:');
            return;
        }

        $state['data']['amount'] = $amount;
        $state['step'] = 2;
        $this->conversationManager->updateState($chatId, $state);

        // Показываем кнопки с категориями
        $user = $this->userRepo->findByTelegramChatId($chatId);
        $categories = $this->categoryRepo->findByFamily($user->getFamily(), CategoryType::Expense);

        $keyboard = $this->buildCategoryKeyboard($categories);
        $this->sender->sendMessage($chatId, '📂 Выберите категорию:', $keyboard);
    }

    private function handleCategory(int $chatId, ?string $callbackData, array $state): void
    {
        if (!$callbackData || !str_starts_with($callbackData, 'category:')) {
            $this->sender->sendMessage($chatId, '❌ Выберите категорию из списка.');
            return;
        }

        $categoryId = substr($callbackData, 9);
        $state['data']['categoryId'] = $categoryId;
        $state['step'] = 3;
        $this->conversationManager->updateState($chatId, $state);

        // Показываем кнопки со счетами
        $user = $this->userRepo->findByTelegramChatId($chatId);
        $accounts = $this->accountRepo->findActiveByFamily($user->getFamily());

        $keyboard = $this->buildAccountKeyboard($accounts);
        $this->sender->sendMessage($chatId, '🏦 Выберите счёт:', $keyboard);
    }

    private function handleAccount(int $chatId, ?string $callbackData, array $state): void
    {
        if (!$callbackData || !str_starts_with($callbackData, 'account:')) {
            return;
        }

        $accountId = substr($callbackData, 8);
        $state['data']['accountId'] = $accountId;

        // Если счёт один или выбран — создаём транзакцию
        $this->createTransaction($chatId, $state);
    }

    private function createTransaction(int $chatId, array $state): void
    {
        $user = $this->userRepo->findByTelegramChatId($chatId);
        $data = $state['data'];

        $transaction = $this->transactionService->addExpense(
            family: $user->getFamily(),
            user: $user,
            amount: $data['amount'],
            category: $this->categoryRepo->find($data['categoryId']),
            account: $this->accountRepo->find($data['accountId']),
            description: $data['description'] ?? '',
        );

        $this->conversationManager->end($chatId);

        $categoryName = $transaction->getCategory()?->getName() ?? 'Без категории';
        $this->sender->sendMessage(
            $chatId,
            "✅ Расход записан!\n\n"
            . "💸 {$transaction->getAmount()} €\n"
            . "📂 {$categoryName}\n"
            . "📅 " . $transaction->getExecutedAt()->format('d.m.Y')
        );
    }

    private function buildCategoryKeyboard(array $categories): array
    {
        $rows = [];
        foreach (array_chunk($categories, 2) as $rowCategories) {
            $row = [];
            foreach ($rowCategories as $category) {
                $row[] = [
                    'text' => $category->getName(),
                    'callback_data' => 'category:' . $category->getId(),
                ];
            }
            $rows[] = $row;
        }

        return ['inline_keyboard' => $rows];
    }
}
```

---

## 4.6 Команды бота

### /start — регистрация пользователя

```php
// src/Telegram/Handler/StartCommandHandler.php

class StartCommandHandler implements CommandHandlerInterface
{
    public function handle(int $chatId, array $update): void
    {
        $telegramUser = $update['message']['from'];
        $user = $this->userRepo->findByTelegramChatId($chatId);

        if ($user) {
            $this->sender->sendMessage($chatId, "👋 С возвращением, {$user->getName()}!");
            $this->sendMainMenu($chatId);
            return;
        }

        // Новый пользователь — начинаем регистрацию
        $this->conversationManager->start($chatId, RegisterConversation::class, [
            'telegramUser' => $telegramUser,
        ]);
    }
}
```

### /balance — текущий баланс

```php
class BalanceCommandHandler implements CommandHandlerInterface
{
    public function handle(int $chatId, array $update): void
    {
        $user = $this->userRepo->findByTelegramChatId($chatId);
        $family = $user->getFamily();
        $accounts = $this->accountRepo->findActiveByFamily($family);

        $text = "💰 *Текущий баланс*\n\n";

        foreach ($accounts as $account) {
            $balance = $this->accountService->getBalance($account);
            $icon = match($account->getType()) {
                AccountType::Checking   => '🏦',
                AccountType::Savings    => '🐷',
                AccountType::Cash       => '💵',
                AccountType::CreditCard => '💳',
            };
            $sign = $balance >= 0 ? '' : '';
            $text .= "{$icon} *{$account->getName()}*: {$sign}{$balance} {$account->getCurrency()}\n";
        }

        $totals = $this->accountService->getTotalBalance($family);
        $text .= "\n📊 *Итого*: ";
        foreach ($totals as $currency => $total) {
            $text .= "{$total} {$currency} ";
        }

        $this->sender->sendMessage($chatId, $text, parseMode: 'Markdown');
    }
}
```

### /report — отчёт за месяц

```php
class ReportCommandHandler implements CommandHandlerInterface
{
    public function handle(int $chatId, array $update): void
    {
        $user = $this->userRepo->findByTelegramChatId($chatId);
        $now = new \DateTimeImmutable();
        $from = $now->modify('first day of this month')->setTime(0, 0);
        $to = $now->modify('last day of this month')->setTime(23, 59, 59);

        $report = $this->analyticsService->getMonthlyReport($user->getFamily(), $from, $to);

        $text = "📊 *Отчёт за " . $now->format('F Y') . "*\n\n";
        $text .= "📥 Доходы: *{$report['income']} €*\n";
        $text .= "📤 Расходы: *{$report['expenses']} €*\n";
        $text .= "💾 Баланс: *{$report['balance']} €*\n\n";
        $text .= "📂 *По категориям:*\n";

        foreach ($report['byCategory'] as $item) {
            $pct = round($item['percent']);
            $text .= "• {$item['category']}: {$item['amount']} € ({$pct}%)\n";
        }

        $keyboard = [
            'inline_keyboard' => [[
                ['text' => '📈 График', 'callback_data' => 'report:chart:month'],
                ['text' => '📋 Детали', 'callback_data' => 'report:details:month'],
            ]]
        ];

        $this->sender->sendMessage($chatId, $text, $keyboard, 'Markdown');
    }
}
```

---

## 4.7 Быстрый ввод транзакции текстом

```php
// src/Telegram/Handler/MessageHandler.php

class MessageHandler
{
    public function handle(array $update): void
    {
        $chatId = $update['message']['chat']['id'];
        $text = $update['message']['text'] ?? '';

        $user = $this->userRepo->findByTelegramChatId($chatId);
        if (!$user) {
            $this->sender->sendMessage($chatId, 'Сначала зарегистрируйтесь: /start');
            return;
        }

        $parsed = $this->quickInputParser->parse($text);

        if (!$parsed) {
            $this->sender->sendMessage(
                $chatId,
                "❓ Не понял. Введите расход в формате:\n`50 продукты`\n`150.50 ресторан`\n\nИли используйте /help",
                parseMode: 'Markdown'
            );
            return;
        }

        // Угадываем категорию
        $category = $this->categoryRuleService->guessCategory(
            $user->getFamily(),
            $parsed->description
        );

        // Берём счёт по умолчанию
        $account = $this->accountRepo->findDefaultForFamily($user->getFamily());

        $transaction = $this->transactionService->addExpense(
            family: $user->getFamily(),
            user: $user,
            amount: $parsed->amount,
            category: $category,
            account: $account,
            description: $parsed->description,
        );

        $categoryText = $category ? "📂 {$category->getName()}" : "📂 Без категории (нажмите для выбора)";

        $keyboard = [
            'inline_keyboard' => [[
                ['text' => '✏️ Категория', 'callback_data' => "tx:category:{$transaction->getId()}"],
                ['text' => '🗑 Удалить', 'callback_data' => "tx:delete:{$transaction->getId()}"],
            ]]
        ];

        $this->sender->sendMessage(
            $chatId,
            "✅ Записал!\n💸 {$parsed->amount} €\n{$categoryText}",
            $keyboard
        );
    }
}
```

---

## 4.8 TelegramSender — отправка сообщений

```php
// src/Telegram/TelegramSender.php

class TelegramSender
{
    private const API_BASE = 'https://api.telegram.org/bot';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly string $botToken,
        private readonly LoggerInterface $logger,
    ) {}

    public function sendMessage(
        int $chatId,
        string $text,
        ?array $replyMarkup = null,
        string $parseMode = '',
    ): void {
        $params = [
            'chat_id' => $chatId,
            'text' => $text,
        ];

        if ($parseMode) {
            $params['parse_mode'] = $parseMode;
        }
        if ($replyMarkup) {
            $params['reply_markup'] = json_encode($replyMarkup);
        }

        try {
            $this->httpClient->request(
                'POST',
                self::API_BASE . $this->botToken . '/sendMessage',
                ['json' => $params]
            );
        } catch (\Exception $e) {
            $this->logger->error('Telegram sendMessage failed', [
                'chatId' => $chatId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function sendPhoto(int $chatId, string $photoPath, string $caption = ''): void
    {
        $this->httpClient->request(
            'POST',
            self::API_BASE . $this->botToken . '/sendPhoto',
            [
                'body' => [
                    'chat_id' => $chatId,
                    'caption' => $caption,
                    'photo' => new DataPart(fopen($photoPath, 'r'), 'chart.png'),
                ],
            ]
        );
    }

    public function answerCallbackQuery(string $callbackQueryId, string $text = ''): void
    {
        $this->httpClient->request(
            'POST',
            self::API_BASE . $this->botToken . '/answerCallbackQuery',
            ['json' => ['callback_query_id' => $callbackQueryId, 'text' => $text]]
        );
    }
}
```

---

## 4.9 Консольная команда для регистрации webhook

```php
// src/Command/TelegramSetWebhookCommand.php

#[AsCommand(name: 'app:telegram:set-webhook')]
class TelegramSetWebhookCommand extends Command
{
    public function __construct(
        private readonly TelegramSender $sender,
        private readonly string $webhookUrl,
        private readonly string $webhookSecret,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $url = "https://api.telegram.org/bot{$this->botToken}/setWebhook";

        $response = $this->httpClient->request('POST', $url, [
            'json' => [
                'url' => $this->webhookUrl,
                'secret_token' => $this->webhookSecret,
                'allowed_updates' => ['message', 'callback_query'],
            ]
        ]);

        $result = $response->toArray();

        if ($result['ok']) {
            $output->writeln('<info>Webhook зарегистрирован: ' . $this->webhookUrl . '</info>');
            return Command::SUCCESS;
        }

        $output->writeln('<error>Ошибка: ' . $result['description'] . '</error>');
        return Command::FAILURE;
    }
}
```

---

## 4.10 Список всех команд бота

| Команда | Описание | Диалог |
|---|---|---|
| `/start` | Регистрация / главное меню | ✅ RegisterConversation |
| `/add` | Добавить расход | ✅ AddExpenseConversation |
| `/income` | Добавить доход | ✅ AddIncomeConversation |
| `/transfer` | Перевод между счетами | ✅ AddTransferConversation |
| `/balance` | Баланс по счетам | — |
| `/report` | Отчёт за месяц | — |
| `/import` | Загрузить выписку | ✅ ImportConversation |
| `/pending` | Неподтверждённые из выписки | — |
| `/budget` | Бюджеты по категориям | — |
| `/stats` | Статистика / графики | — |
| `/settings` | Настройки | ✅ SettingsConversation |
| `/help` | Справка | — |
| `/cancel` | Отменить текущий диалог | — |

---

## Результат шага 4

- Webhook-контроллер с проверкой секрета
- Маршрутизация команд и сообщений
- Состояния диалогов через Redis
- Диалог добавления расхода с кнопками категорий
- Быстрый ввод текстом: `50 продукты`
- Команды `/start`, `/balance`, `/report`

## Следующий шаг

→ [05-bank-import.md](05-bank-import.md) — Импорт выписок из Sparkasse (MT940, CSV)
