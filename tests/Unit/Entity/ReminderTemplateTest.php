<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\ReminderFrequency;
use App\Entity\ReminderTemplate;
use App\Entity\ReminderType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ReminderTemplateTest extends TestCase
{
    // ── helpers ──────────────────────────────────────────────────────────────

    private function makeTemplate(
        ReminderFrequency $frequency,
        int $sendHour,
        ?int $dayOfWeek = null,
        ?int $dayOfMonth = null,
        ?\DateTimeImmutable $lastSentAt = null,
    ): ReminderTemplate {
        $t = new ReminderTemplate();
        $t->setName('Test')
          ->setType(ReminderType::Custom)
          ->setFrequency($frequency)
          ->setSendHour($sendHour)
          ->setDayOfWeek($dayOfWeek)
          ->setDayOfMonth($dayOfMonth)
          ->setMessageText('test')
          ->setIsActive(true)
          ->setLastSentAt($lastSentAt);

        return $t;
    }

    /** Returns a DateTimeImmutable with specific components, everything else zeroed out. */
    private function makeNow(
        string $date,   // 'Y-m-d'
        int $hour,
    ): \DateTimeImmutable {
        return new \DateTimeImmutable("{$date} {$hour}:00:00");
    }

    // ── hour gate ─────────────────────────────────────────────────────────────

    public function testNotDueWhenHourDoesNotMatch(): void
    {
        $t   = $this->makeTemplate(ReminderFrequency::Daily, sendHour: 9);
        $now = $this->makeNow('2025-06-10', hour: 8); // wrong hour

        self::assertFalse($t->isDue($now));
    }

    public function testNotDueAtNextHourEither(): void
    {
        $t   = $this->makeTemplate(ReminderFrequency::Daily, sendHour: 9);
        $now = $this->makeNow('2025-06-10', hour: 10);

        self::assertFalse($t->isDue($now));
    }

    // ── daily ─────────────────────────────────────────────────────────────────

    public function testDailyDueWhenNeverSent(): void
    {
        $t   = $this->makeTemplate(ReminderFrequency::Daily, sendHour: 9, lastSentAt: null);
        $now = $this->makeNow('2025-06-10', hour: 9);

        self::assertTrue($t->isDue($now));
    }

    public function testDailyDueWhenLastSentYesterday(): void
    {
        $t = $this->makeTemplate(
            ReminderFrequency::Daily,
            sendHour: 9,
            lastSentAt: new \DateTimeImmutable('2025-06-09 09:00:00'),
        );
        $now = $this->makeNow('2025-06-10', hour: 9);

        self::assertTrue($t->isDue($now));
    }

    public function testDailyNotDueWhenAlreadySentToday(): void
    {
        $t = $this->makeTemplate(
            ReminderFrequency::Daily,
            sendHour: 9,
            lastSentAt: new \DateTimeImmutable('2025-06-10 09:00:00'),
        );
        $now = $this->makeNow('2025-06-10', hour: 9);

        self::assertFalse($t->isDue($now));
    }

    public function testDailyDueAtMidnight(): void
    {
        $t   = $this->makeTemplate(ReminderFrequency::Daily, sendHour: 0, lastSentAt: null);
        $now = $this->makeNow('2025-06-10', hour: 0);

        self::assertTrue($t->isDue($now));
    }

    public function testDailyDueAt23(): void
    {
        $t   = $this->makeTemplate(ReminderFrequency::Daily, sendHour: 23, lastSentAt: null);
        $now = $this->makeNow('2025-06-10', hour: 23);

        self::assertTrue($t->isDue($now));
    }

    // ── weekly ────────────────────────────────────────────────────────────────

    public function testWeeklyDueOnCorrectDayNeverSent(): void
    {
        // 2025-06-09 is Monday (ISO day 1)
        $t   = $this->makeTemplate(ReminderFrequency::Weekly, sendHour: 9, dayOfWeek: 1, lastSentAt: null);
        $now = $this->makeNow('2025-06-09', hour: 9);

        self::assertTrue($t->isDue($now));
    }

    public function testWeeklyNotDueOnWrongDay(): void
    {
        // 2025-06-10 is Tuesday (ISO day 2), template wants Monday (1)
        $t   = $this->makeTemplate(ReminderFrequency::Weekly, sendHour: 9, dayOfWeek: 1, lastSentAt: null);
        $now = $this->makeNow('2025-06-10', hour: 9);

        self::assertFalse($t->isDue($now));
    }

    public function testWeeklyNotDueAlreadySentThisWeek(): void
    {
        // Both 2025-06-09 (Mon) and 2025-06-09 are ISO week 2025-W24
        $t = $this->makeTemplate(
            ReminderFrequency::Weekly,
            sendHour: 9,
            dayOfWeek: 1,
            lastSentAt: new \DateTimeImmutable('2025-06-09 09:00:00'),
        );
        $now = $this->makeNow('2025-06-09', hour: 9);

        self::assertFalse($t->isDue($now));
    }

    public function testWeeklyDueWhenLastSentPreviousWeek(): void
    {
        // 2025-06-02 (Mon, week 23) vs 2025-06-09 (Mon, week 24)
        $t = $this->makeTemplate(
            ReminderFrequency::Weekly,
            sendHour: 9,
            dayOfWeek: 1,
            lastSentAt: new \DateTimeImmutable('2025-06-02 09:00:00'),
        );
        $now = $this->makeNow('2025-06-09', hour: 9);

        self::assertTrue($t->isDue($now));
    }

    public function testWeeklyWithNullDayOfWeekFiresEveryWeek(): void
    {
        // dayOfWeek = null means any day
        $t = $this->makeTemplate(
            ReminderFrequency::Weekly,
            sendHour: 9,
            dayOfWeek: null,
            lastSentAt: new \DateTimeImmutable('2025-06-02 09:00:00'), // previous week
        );
        $now = $this->makeNow('2025-06-10', hour: 9); // Tuesday

        self::assertTrue($t->isDue($now));
    }

    // ── monthly ───────────────────────────────────────────────────────────────

    public function testMonthlyDueOnCorrectDayNeverSent(): void
    {
        $t   = $this->makeTemplate(ReminderFrequency::Monthly, sendHour: 9, dayOfMonth: 10, lastSentAt: null);
        $now = $this->makeNow('2025-06-10', hour: 9);

        self::assertTrue($t->isDue($now));
    }

    public function testMonthlyNotDueOnWrongDay(): void
    {
        $t   = $this->makeTemplate(ReminderFrequency::Monthly, sendHour: 9, dayOfMonth: 15, lastSentAt: null);
        $now = $this->makeNow('2025-06-10', hour: 9);

        self::assertFalse($t->isDue($now));
    }

    public function testMonthlyNotDueAlreadySentThisMonth(): void
    {
        $t = $this->makeTemplate(
            ReminderFrequency::Monthly,
            sendHour: 9,
            dayOfMonth: 10,
            lastSentAt: new \DateTimeImmutable('2025-06-10 09:00:00'),
        );
        $now = $this->makeNow('2025-06-10', hour: 9);

        self::assertFalse($t->isDue($now));
    }

    public function testMonthlyDueWhenLastSentPreviousMonth(): void
    {
        $t = $this->makeTemplate(
            ReminderFrequency::Monthly,
            sendHour: 9,
            dayOfMonth: 10,
            lastSentAt: new \DateTimeImmutable('2025-05-10 09:00:00'),
        );
        $now = $this->makeNow('2025-06-10', hour: 9);

        self::assertTrue($t->isDue($now));
    }

    public function testMonthlyWithNullDayOfMonthFiresEveryMonth(): void
    {
        $t = $this->makeTemplate(
            ReminderFrequency::Monthly,
            sendHour: 9,
            dayOfMonth: null,
            lastSentAt: new \DateTimeImmutable('2025-05-01 09:00:00'),
        );
        $now = $this->makeNow('2025-06-10', hour: 9);

        self::assertTrue($t->isDue($now));
    }

    public function testMonthlyOnLastDayOfMonth(): void
    {
        $t   = $this->makeTemplate(ReminderFrequency::Monthly, sendHour: 8, dayOfMonth: 31, lastSentAt: null);
        $now = $this->makeNow('2025-01-31', hour: 8);

        self::assertTrue($t->isDue($now));
    }

    // ── year boundary ─────────────────────────────────────────────────────────

    public function testWeeklyAcrossYearBoundary(): void
    {
        // 2024-12-30 (Mon) is ISO week 2025-W01, 2025-01-06 (Mon) is also W01 — same ISO week
        // So should NOT fire again in the same ISO week even across the calendar year
        $t = $this->makeTemplate(
            ReminderFrequency::Weekly,
            sendHour: 9,
            dayOfWeek: 1,
            lastSentAt: new \DateTimeImmutable('2024-12-30 09:00:00'),
        );
        $now = $this->makeNow('2025-01-06', hour: 9); // 2025-W02

        self::assertTrue($t->isDue($now)); // different ISO week → due
    }

    public function testMonthlyAcrossYearBoundary(): void
    {
        $t = $this->makeTemplate(
            ReminderFrequency::Monthly,
            sendHour: 9,
            dayOfMonth: 1,
            lastSentAt: new \DateTimeImmutable('2024-12-01 09:00:00'),
        );
        $now = $this->makeNow('2025-01-01', hour: 9);

        self::assertTrue($t->isDue($now)); // different Y-m → due
    }
}
