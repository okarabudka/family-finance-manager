<?php

declare(strict_types=1);

namespace App\Telegram\Handler;

use App\Domain\Analytics\AnalyticsService;
use App\Repository\BudgetRepository;
use App\Repository\UserRepository;
use App\Telegram\CommandHandlerInterface;
use App\Telegram\TelegramSender;

class BudgetCommandHandler implements CommandHandlerInterface
{
    public function __construct(
        private readonly UserRepository $userRepo,
        private readonly BudgetRepository $budgetRepo,
        private readonly AnalyticsService $analyticsService,
        private readonly TelegramSender $sender,
    ) {}

    /** @param array<string, mixed> $update */
    public function handle(int $chatId, array $update): void
    {
        $user = $this->userRepo->findByTelegramChatId($chatId);
        if ($user === null || $user->getFamily() === null) {
            $this->sender->sendMessage($chatId, 'Please register first: /start');

            return;
        }

        $family = $user->getFamily();
        $now    = new \DateTimeImmutable();
        $year   = (int) $now->format('Y');
        $month  = (int) $now->format('n');

        $budgets = $this->budgetRepo->findByFamilyAndMonth($family, $year, $month);

        if (count($budgets) === 0) {
            $this->sender->sendMessage($chatId, 'No budgets set for this month.');

            return;
        }

        $from = $now->modify('first day of this month')->setTime(0, 0);
        $to   = $now->modify('last day of this month')->setTime(23, 59, 59);

        $report = $this->analyticsService->getMonthlyReport($family, $from, $to);

        // Index actual spending by category ID for fast lookup
        $actualByCategory = [];
        foreach ($report->byCategory as $categoryReport) {
            $actualByCategory[(string) $categoryReport->category->getId()] = $categoryReport->amount;
        }

        $monthLabel = $now->format('F Y');
        $currency   = $family->getCurrency();
        $text       = "*Budget for {$monthLabel}:*\n\n";

        foreach ($budgets as $budget) {
            $planned      = (float) $budget->getPlannedAmount();
            $categoryId   = (string) $budget->getCategory()->getId();
            $actual       = $actualByCategory[$categoryId] ?? 0.0;
            $pct          = $planned > 0 ? (int) round($actual / $planned * 100) : 0;
            $categoryName = $budget->getCategory()->getName();

            $plannedFormatted = number_format($planned, 2, '.', ' ');
            $actualFormatted  = number_format($actual, 2, '.', ' ');

            $text .= "{$categoryName}: {$actualFormatted}/{$plannedFormatted} {$currency} ({$pct}%)\n";
        }

        $this->sender->sendMessage($chatId, $text, parseMode: 'Markdown');
    }
}
