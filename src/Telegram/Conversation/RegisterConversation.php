<?php

declare(strict_types=1);

namespace App\Telegram\Conversation;

use App\Entity\Account;
use App\Entity\AccountType;
use App\Entity\Family;
use App\Entity\User;
use App\Repository\UserRepository;
use App\Telegram\ConversationInterface;
use App\Telegram\ConversationManager;
use App\Telegram\Payload;
use App\Telegram\TelegramSender;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use App\Telegram\BotTranslator;

class RegisterConversation implements ConversationInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ConversationManager $conversationManager,
        private readonly TelegramSender $sender,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly UserRepository $userRepo,
        private readonly BotTranslator $translator,
    ) {}

    /**
     * @param array<string, mixed> $update
     * @param array<string, mixed> $state
     */
    public function handle(int $chatId, array $update, array $state): void
    {
        $step = Payload::int($state, 'step') ?? 0;
        $text = Payload::text($update);

        match ($step) {
            0 => $this->askName($chatId, $state),
            1 => $this->handleName($chatId, $text, $state),
            default => null,
        };
    }

    /** @param array<string, mixed> $state */
    private function askName(int $chatId, array $state): void
    {
        $this->sender->sendMessage($chatId, $this->translator->trans('register.ask_name'));
        $state['step'] = 1;
        $this->conversationManager->updateState($chatId, $state);
    }

    /** @param array<string, mixed> $state */
    private function handleName(int $chatId, string $text, array $state): void
    {
        $name = trim($text);

        if (mb_strlen($name) < 2) {
            $this->sender->sendMessage($chatId, $this->translator->trans('register.name_too_short'));

            return;
        }

        if ($this->userRepo->findByTelegramChatId($chatId) !== null) {
            $this->conversationManager->end($chatId);
            $this->sender->sendMessage($chatId, $this->translator->trans('register.already_registered'));

            return;
        }

        $telegramId       = Payload::string($state, 'data', 'telegramUser', 'id') ?? (string) $chatId;
        $telegramUsername = Payload::string($state, 'data', 'telegramUser', 'username');

        // Create a family
        $family = new Family();
        $family->setName($this->translator->trans('register.family_name', ['%name%' => $name]))->setCurrency('EUR');
        $this->em->persist($family);

        // Create the user
        $user = new User();
        $user->setName($name)
             ->setEmail("telegram_{$telegramId}@local")
             ->setPassword($this->passwordHasher->hashPassword($user, bin2hex(random_bytes(32))))
             ->setTelegramChatId($chatId)
             ->setTelegramUsername($telegramUsername)
             ->setFamily($family)
             ->setRoles(['ROLE_USER']);

        // Create default accounts
        $bankAccount = (new Account())
            ->setName($this->translator->trans('register.main_account'))
            ->setType(AccountType::Checking)
            ->setFamily($family)
            ->setIsDefault(true);

        $cashAccount = (new Account())
            ->setName($this->translator->trans('register.cash_account'))
            ->setType(AccountType::Cash)
            ->setFamily($family);

        $this->em->persist($user);
        $this->em->persist($bankAccount);
        $this->em->persist($cashAccount);
        $this->em->flush();

        $this->conversationManager->end($chatId);

        $this->sender->sendMessage(
            $chatId,
            $this->translator->trans('register.done', [
                '%name%'         => $name,
                '%main_account%' => $bankAccount->getName(),
                '%cash_account%' => $cashAccount->getName(),
            ]),
            parseMode: 'Markdown',
        );
    }
}
