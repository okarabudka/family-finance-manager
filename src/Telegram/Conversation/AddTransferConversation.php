<?php

declare(strict_types=1);

namespace App\Telegram\Conversation;

use App\Domain\Transaction\TransactionService;
use App\Repository\AccountRepository;
use App\Repository\UserRepository;
use App\Telegram\ConversationInterface;
use App\Telegram\ConversationManager;
use App\Telegram\TelegramSender;

class AddTransferConversation implements ConversationInterface
{
    public function __construct(
        private readonly TelegramSender $sender,
        private readonly TransactionService $transactionService,
        private readonly AccountRepository $accountRepo,
        private readonly ConversationManager $conversationManager,
        private readonly UserRepository $userRepo,
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
            2 => $this->handleFromAccount($chatId, $callbackData, $state),
            3 => $this->handleToAccount($chatId, $callbackData, $state),
            4 => $this->confirm($chatId, $state),
            default => null,
        };
    }

    /** @param array<string, mixed> $state */
    private function askAmount(int $chatId, array $state): void
    {
        $this->sender->sendMessage($chatId, 'Enter the transfer amount:');
        $state['step'] = 1;
        $this->conversationManager->updateState($chatId, $state);
    }

    /** @param array<string, mixed> $state */
    private function handleAmount(int $chatId, string $text, array $state): void
    {
        $amount = (float) str_replace(',', '.', trim($text));

        if ($amount <= 0.0) {
            $this->sender->sendMessage($chatId, 'Invalid amount. Enter a number greater than 0:');

            return;
        }

        $state['data']['amount'] = $amount;
        $state['step']           = 2;
        $this->conversationManager->updateState($chatId, $state);

        $user = $this->userRepo->findByTelegramChatId($chatId);
        if ($user === null || $user->getFamily() === null) {
            $this->sender->sendMessage($chatId, 'Please register first: /start');
            $this->conversationManager->end($chatId);

            return;
        }

        $accounts = $this->accountRepo->findActiveByFamily($user->getFamily());
        $keyboard = $this->buildAccountKeyboard($accounts, 'from_account');
        $this->sender->sendMessage($chatId, 'Choose the source account (transfer from):', $keyboard);
    }

    /** @param array<string, mixed> $state */
    private function handleFromAccount(int $chatId, ?string $callbackData, array $state): void
    {
        if ($callbackData === null || !str_starts_with($callbackData, 'from_account:')) {
            $this->sender->sendMessage($chatId, 'Please choose an account from the list.');

            return;
        }

        $accountId                     = substr($callbackData, 13);
        $state['data']['fromAccountId'] = $accountId;
        $state['step']                 = 3;
        $this->conversationManager->updateState($chatId, $state);

        $user = $this->userRepo->findByTelegramChatId($chatId);
        if ($user === null || $user->getFamily() === null) {
            $this->sender->sendMessage($chatId, 'Please register first: /start');
            $this->conversationManager->end($chatId);

            return;
        }

        $accounts = $this->accountRepo->findActiveByFamily($user->getFamily());
        $keyboard = $this->buildAccountKeyboard($accounts, 'to_account');
        $this->sender->sendMessage($chatId, 'Choose the destination account (transfer to):', $keyboard);
    }

    /** @param array<string, mixed> $state */
    private function handleToAccount(int $chatId, ?string $callbackData, array $state): void
    {
        if ($callbackData === null || !str_starts_with($callbackData, 'to_account:')) {
            $this->sender->sendMessage($chatId, 'Please choose an account from the list.');

            return;
        }

        $accountId                   = substr($callbackData, 11);
        $state['data']['toAccountId'] = $accountId;
        $state['step']               = 4;
        $this->conversationManager->updateState($chatId, $state);

        $this->confirm($chatId, $state);
    }

    /** @param array<string, mixed> $state */
    private function confirm(int $chatId, array $state): void
    {
        $user = $this->userRepo->findByTelegramChatId($chatId);
        if ($user === null || $user->getFamily() === null) {
            $this->sender->sendMessage($chatId, 'Please register first: /start');
            $this->conversationManager->end($chatId);

            return;
        }

        $data = $state['data'];

        $fromAccount = $this->accountRepo->findByFamilyAndId($user->getFamily(), $data['fromAccountId']);
        if ($fromAccount === null) {
            $this->sender->sendMessage($chatId, 'Source account not found. Please start over: /transfer');
            $this->conversationManager->end($chatId);

            return;
        }

        $toAccount = $this->accountRepo->findByFamilyAndId($user->getFamily(), $data['toAccountId']);
        if ($toAccount === null) {
            $this->sender->sendMessage($chatId, 'Destination account not found. Please start over: /transfer');
            $this->conversationManager->end($chatId);

            return;
        }

        $transaction = $this->transactionService->addTransfer(
            family:      $user->getFamily(),
            user:        $user,
            amount:      (string) $data['amount'],
            from:        $fromAccount,
            to:          $toAccount,
            description: $data['description'] ?? '',
        );

        $this->conversationManager->end($chatId);

        $amountFormatted = number_format((float) $transaction->getAmount(), 2, '.', ' ');

        $this->sender->sendMessage(
            $chatId,
            "Transfer recorded!\n\n"
            . "{$amountFormatted} {$transaction->getCurrency()}\n"
            . "From: {$fromAccount->getName()}\n"
            . "To: {$toAccount->getName()}\n"
            . $transaction->getExecutedAt()->format('d.m.Y'),
        );
    }

    /**
     * @param \App\Entity\Account[] $accounts
     * @return array<string, mixed>
     */
    private function buildAccountKeyboard(array $accounts, string $prefix): array
    {
        $rows = [];
        foreach (array_chunk($accounts, 2) as $rowAccounts) {
            $row = [];
            foreach ($rowAccounts as $account) {
                $row[] = [
                    'text'          => $account->getName(),
                    'callback_data' => $prefix . ':' . $account->getId(),
                ];
            }
            $rows[] = $row;
        }

        return ['inline_keyboard' => $rows];
    }
}
