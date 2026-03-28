<?php

declare(strict_types=1);

namespace App\Telegram\Conversation;

use App\Entity\Account;
use App\Entity\AccountType;
use App\Entity\Family;
use App\Entity\User;
use App\Telegram\ConversationInterface;
use App\Telegram\ConversationManager;
use App\Telegram\TelegramSender;
use Doctrine\ORM\EntityManagerInterface;

class RegisterConversation implements ConversationInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ConversationManager $conversationManager,
        private readonly TelegramSender $sender,
    ) {}

    /**
     * @param array<string, mixed> $update
     * @param array<string, mixed> $state
     */
    public function handle(int $chatId, array $update, array $state): void
    {
        $step = $state['step'] ?? 0;
        $text = $update['message']['text'] ?? '';

        match ($step) {
            0 => $this->askName($chatId, $state),
            1 => $this->handleName($chatId, $text, $state),
            default => null,
        };
    }

    /** @param array<string, mixed> $state */
    private function askName(int $chatId, array $state): void
    {
        $this->sender->sendMessage($chatId, 'Welcome! What is your name?');
        $state['step'] = 1;
        $this->conversationManager->updateState($chatId, $state);
    }

    /** @param array<string, mixed> $state */
    private function handleName(int $chatId, string $text, array $state): void
    {
        $name = trim($text);

        if (mb_strlen($name) < 2) {
            $this->sender->sendMessage($chatId, 'Name is too short. Please try again:');

            return;
        }

        $telegramUser = $state['data']['telegramUser'] ?? [];
        $telegramId   = (string) ($telegramUser['id'] ?? $chatId);

        // Create a family
        $family = new Family();
        $family->setName("{$name}'s Family")->setCurrency('EUR');
        $this->em->persist($family);

        // Create the user
        $user = new User();
        $user->setName($name)
             ->setEmail("telegram_{$telegramId}@local")
             ->setPassword('') // No web login for Telegram-only users
             ->setTelegramChatId($chatId)
             ->setTelegramUsername($telegramUser['username'] ?? null)
             ->setFamily($family)
             ->setRoles(['ROLE_USER']);

        // Create default accounts
        $bankAccount = (new Account())
            ->setName('Main Account')
            ->setType(AccountType::Checking)
            ->setFamily($family)
            ->setIsDefault(true);

        $cashAccount = (new Account())
            ->setName('Cash')
            ->setType(AccountType::Cash)
            ->setFamily($family);

        $this->em->persist($user);
        $this->em->persist($bankAccount);
        $this->em->persist($cashAccount);
        $this->em->flush();

        $this->conversationManager->end($chatId);

        $this->sender->sendMessage(
            $chatId,
            "Done, {$name}! Your account is created.\n\n"
            . "Default accounts:\n- Main Account\n- Cash\n\n"
            . "Try adding your first expense:\n`50 groceries`",
            parseMode: 'Markdown',
        );
    }
}
