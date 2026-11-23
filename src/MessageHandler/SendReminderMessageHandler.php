<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Entity\ReminderTemplate;
use App\Entity\User;
use App\Message\SendReminderMessage;
use App\Repository\ReminderTemplateRepository;
use App\Repository\UserRepository;
use App\Telegram\TelegramSender;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Mime\Email;

#[AsMessageHandler]
class SendReminderMessageHandler
{
    public function __construct(
        private readonly ReminderTemplateRepository $reminderTemplateRepository,
        private readonly UserRepository $userRepo,
        private readonly TelegramSender $telegramSender,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
        private readonly MailerInterface $mailer,
        private readonly string $mailerFrom = 'noreply@family-finance.local',
    ) {
    }

    public function __invoke(SendReminderMessage $message): void
    {
        $template = $this->reminderTemplateRepository->find($message->reminderTemplateId);

        if (null === $template || !$template->isActive()) {
            return;
        }

        $recipients = $this->resolveRecipients($template);

        foreach ($recipients as $user) {
            if ($template->isSendViaTelegram()) {
                $this->sendTelegram($user, $template->getMessageText());
            }

            if ($template->isSendViaEmail()) {
                $this->sendEmail($user, $template);
            }
        }

        $template->setLastSentAt(new \DateTimeImmutable());
        $this->em->flush();
    }

    /** @return User[] */
    private function resolveRecipients(ReminderTemplate $template): array
    {
        $family = $template->getFamily();

        if (null !== $family) {
            return $this->userRepo->findByFamily($family);
        }

        // Global reminder — send to all users
        return $this->userRepo->findAll();
    }

    private function sendTelegram(User $user, string $text): void
    {
        $chatId = $user->getTelegramChatId();
        if (null === $chatId) {
            return;
        }

        try {
            $this->telegramSender->sendMessage($chatId, $text);
        } catch (\Throwable $e) {
            $this->logger->error('Reminder Telegram send failed', [
                'user' => (string) $user->getId(),
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function sendEmail(User $user, ReminderTemplate $template): void
    {
        try {
            $email = (new Email())
                ->from($this->mailerFrom)
                ->to($user->getEmail())
                ->subject('[Family Finance] '.$template->getName())
                ->text($template->getMessageText());

            $this->mailer->send($email);
        } catch (\Throwable $e) {
            $this->logger->error('Reminder email send failed', [
                'user' => (string) $user->getId(),
                'error' => $e->getMessage(),
            ]);
        }
    }
}
