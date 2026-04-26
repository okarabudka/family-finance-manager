<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\IdGenerator\UuidGenerator;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: \App\Repository\ReminderTemplateRepository::class)]
#[ORM\Table(name: 'reminder_templates')]
class ReminderTemplate
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: UuidGenerator::class)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Family::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?Family $family = null;

    #[ORM\Column(length: 100)]
    private string $name;

    #[ORM\Column(enumType: ReminderType::class)]
    private ReminderType $type;

    #[ORM\Column(enumType: ReminderFrequency::class)]
    private ReminderFrequency $frequency;

    /** Day of week for weekly reminders: 1 (Mon) – 7 (Sun) */
    #[ORM\Column(type: 'smallint', nullable: true)]
    private ?int $dayOfWeek = null;

    /** Day of month for monthly reminders: 1 – 31 */
    #[ORM\Column(type: 'smallint', nullable: true)]
    private ?int $dayOfMonth = null;

    /** Hour to send (0–23) */
    #[ORM\Column(type: 'smallint', options: ['default' => 9])]
    private int $sendHour = 9;

    #[ORM\Column(type: 'text')]
    private string $messageText;

    #[ORM\Column(options: ['default' => true])]
    private bool $sendViaTelegram = true;

    #[ORM\Column(options: ['default' => false])]
    private bool $sendViaEmail = false;

    #[ORM\Column(options: ['default' => true])]
    private bool $isActive = true;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $lastSentAt = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getFamily(): ?Family
    {
        return $this->family;
    }

    public function setFamily(?Family $family): static
    {
        $this->family = $family;
        return $this;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;
        return $this;
    }

    public function getType(): ReminderType
    {
        return $this->type;
    }

    public function setType(ReminderType $type): static
    {
        $this->type = $type;
        return $this;
    }

    public function getFrequency(): ReminderFrequency
    {
        return $this->frequency;
    }

    public function setFrequency(ReminderFrequency $frequency): static
    {
        $this->frequency = $frequency;
        return $this;
    }

    public function getDayOfWeek(): ?int
    {
        return $this->dayOfWeek;
    }

    public function setDayOfWeek(?int $dayOfWeek): static
    {
        $this->dayOfWeek = $dayOfWeek;
        return $this;
    }

    public function getDayOfMonth(): ?int
    {
        return $this->dayOfMonth;
    }

    public function setDayOfMonth(?int $dayOfMonth): static
    {
        $this->dayOfMonth = $dayOfMonth;
        return $this;
    }

    public function getSendHour(): int
    {
        return $this->sendHour;
    }

    public function setSendHour(int $sendHour): static
    {
        $this->sendHour = $sendHour;
        return $this;
    }

    public function getMessageText(): string
    {
        return $this->messageText;
    }

    public function setMessageText(string $messageText): static
    {
        $this->messageText = $messageText;
        return $this;
    }

    public function isSendViaTelegram(): bool
    {
        return $this->sendViaTelegram;
    }

    public function setSendViaTelegram(bool $sendViaTelegram): static
    {
        $this->sendViaTelegram = $sendViaTelegram;
        return $this;
    }

    public function isSendViaEmail(): bool
    {
        return $this->sendViaEmail;
    }

    public function setSendViaEmail(bool $sendViaEmail): static
    {
        $this->sendViaEmail = $sendViaEmail;
        return $this;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function setIsActive(bool $isActive): static
    {
        $this->isActive = $isActive;
        return $this;
    }

    public function getLastSentAt(): ?\DateTimeImmutable
    {
        return $this->lastSentAt;
    }

    public function setLastSentAt(?\DateTimeImmutable $lastSentAt): static
    {
        $this->lastSentAt = $lastSentAt;
        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /** Returns true if this reminder is due to be sent right now. */
    public function isDue(\DateTimeImmutable $now): bool
    {
        if ((int) $now->format('G') !== $this->sendHour) {
            return false;
        }

        return match ($this->frequency) {
            ReminderFrequency::Daily   => $this->notSentToday($now),
            ReminderFrequency::Weekly  => $this->isCorrectDayOfWeek($now) && $this->notSentThisWeek($now),
            ReminderFrequency::Monthly => $this->isCorrectDayOfMonth($now) && $this->notSentThisMonth($now),
        };
    }

    private function notSentToday(\DateTimeImmutable $now): bool
    {
        if ($this->lastSentAt === null) {
            return true;
        }
        return $this->lastSentAt->format('Y-m-d') !== $now->format('Y-m-d');
    }

    private function notSentThisWeek(\DateTimeImmutable $now): bool
    {
        if ($this->lastSentAt === null) {
            return true;
        }
        return $this->lastSentAt->format('o-W') !== $now->format('o-W');
    }

    private function notSentThisMonth(\DateTimeImmutable $now): bool
    {
        if ($this->lastSentAt === null) {
            return true;
        }
        return $this->lastSentAt->format('Y-m') !== $now->format('Y-m');
    }

    private function isCorrectDayOfWeek(\DateTimeImmutable $now): bool
    {
        return $this->dayOfWeek === null || (int) $now->format('N') === $this->dayOfWeek;
    }

    private function isCorrectDayOfMonth(\DateTimeImmutable $now): bool
    {
        return $this->dayOfMonth === null || (int) $now->format('j') === $this->dayOfMonth;
    }
}
