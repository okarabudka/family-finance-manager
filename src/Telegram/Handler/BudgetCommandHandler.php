<?php

declare(strict_types=1);

namespace App\Telegram\Handler;

use App\Domain\Analytics\AnalyticsService;
use App\Repository\BudgetRepository;
use App\Repository\UserRepository;
use App\Telegram\BotTranslator;
use App\Telegram\CommandHandlerInterface;
use App\Telegram\TelegramSender;

class BudgetCommandHandler implements CommandHandlerInterface
{
    public function __construct(
        private readonly UserRepository $userRepo,
        private readonly BudgetRepository $budgetRepo,
        private readonly AnalyticsService $analyticsService,
        private readonly TelegramSender $sender,
        private readonly BotTranslator $translator,
    ) {
    }

    /** @param array<string, mixed> $update */
    public function handle(int $chatId, array $update): void
    {
        $user = $this->userRepo->findByTelegramChatId($chatId);
        if (null === $user || null === $user->getFamily()) {
            $this->sender->sendMessage($chatId, $this->translator->trans('common.register_first'));

            return;
        }

        $family = $user->getFamily();
        $now = new \DateTimeImmutable();
        $year = (int) $now->format('Y');
        $month = (int) $now->format('n');

        $budgets = $this->budgetRepo->findByFamilyAndMonth($family, $year, $month);

        if (0 === count($budgets)) {
            $this->sender->sendMessage($chatId, $this->translator->trans('budget.none'));

            return;
        }

        $from = $now->modify('first day of this month')->setTime(0, 0);
        $to = $now->modify('last day of this month')->setTime(23, 59, 59);

        $report = $this->analyticsService->getMonthlyReport($family, $from, $to);

        // Index actual spending by category ID for fast lookup
        $actualByCategory = [];
        foreach ($report->byCategory as $categoryReport) {
            $actualByCategory[(string) $categoryReport->category->getId()] = $categoryReport->amount;
        }

        $monthLabel = $this->translator->monthLabel($now);
        $currency = $family->getCurrency();
        $text = $this->translator->trans('budget.title', ['%month%' => $monthLabel])."\n\n";

        foreach ($budgets as $budget) {
            $planned = (float) $budget->getPlannedAmount();
            $categoryId = (string) $budget->getCategory()->getId();
            $actual = $actualByCategory[$categoryId] ?? 0.0;
            $pct = $planned > 0 ? (int) round($actual / $planned * 100) : 0;
            $categoryName = $budget->getCategory()->getName();

            $plannedFormatted = number_format($planned, 2, '.', ' ');
            $actualFormatted = number_format($actual, 2, '.', ' ');

            $text .= "{$categoryName}: {$actualFormatted}/{$plannedFormatted} {$currency} ({$pct}%)\n";
        }

        $this->sender->sendMessage($chatId, $text, parseMode: 'Markdown');
    }
}
